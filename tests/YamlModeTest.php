<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polytypo\Engine\YamlKeys;
use Polytypo\Modes\Yaml;
use Polytypo\Polytypo;
use Polytypo\PolytypoException;

/**
 * spec/rules/modes.md 3.8 -- the `yaml` mode's span selection, its required `keys` option, and the
 * accepted misses of section 7.11. The scan is specified rather than delegated (3.8.1), and this
 * runtime is half the reason: symfony/yaml reports no positions at all, so there was never a
 * parser to delegate to. A test here is one of the few things standing between five hand-written
 * scanners and five different answers.
 */
final class YamlModeTest extends TestCase
{
    /** @return list<string> */
    private static function proseKeys(): array
    {
        return ['description', 'summary', 'title', 'a', 'b', 'c', 'k', 'n', 'inner', 'use'];
    }

    /** @param list<string>|null $keys */
    private static function yaml(string $source, string $locale = 'en-US', ?array $keys = null): string
    {
        return Polytypo::transform($source, $locale, 'yaml', null, null, null, $keys ?? self::proseKeys());
    }

    /**
     * @param list<string>|null $keys
     * @return list<string>
     */
    private static function spanText(string $source, ?array $keys = null): array
    {
        $chars = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach (Yaml::yamlSpans($source, YamlKeys::resolve($keys ?? self::proseKeys())) as $span) {
            $out[] = implode('', array_slice($chars, $span->start, $span->end - $span->start));
        }

        return $out;
    }

    public function testKeysIsRequiredWithNoDefault(): void
    {
        $this->expectException(PolytypoException::class);
        Polytypo::transform("a: one two\n", 'en-US', 'yaml');
    }

    public function testKeysRejectsANonList(): void
    {
        try {
            /** @phpstan-ignore-next-line deliberately wrong type, to prove the check is at runtime too */
            Polytypo::transform("a: one two\n", 'en-US', 'yaml', null, null, null, [7]);
            self::fail('expected POLYTYPO_INVALID_OPTION');
        } catch (PolytypoException $e) {
            self::assertSame(PolytypoException::CODE_INVALID_OPTION, $e->getErrorCode());
        }
    }

    public function testAnEmptyKeysListIsLegalAndProcessesNothing(): void
    {
        self::assertSame("description: one...two\n", self::yaml("description: one...two\n", 'en-US', []));
    }

    public function testProcessesAListedKeyAndLeavesAnUnlistedOne(): void
    {
        self::assertSame(
            "description: one…two\nrun: three...four\n",
            self::yaml("description: one...two\nrun: three...four\n", 'en-US', ['description']),
        );
    }

    public function testMatchesTheSameKeyAtAnyDepth(): void
    {
        self::assertSame(
            "description: one…\nnested:\n  description: two…\n",
            self::yaml("description: one...\nnested:\n  description: two...\n", 'en-US', ['description']),
        );
    }

    public function testMatchesCodePointForCodePointWithNoCaseFolding(): void
    {
        self::assertSame([], self::spanText("Description: one two\n", ['description']));
        self::assertSame(['one two'], self::spanText("Description: one two\n", ['Description']));
    }

    public function testIgnoresTrailingSpacesBeforeTheColon(): void
    {
        self::assertSame(['one two'], self::spanText("description  : one two\n", ['description']));
    }

    public function testNeverMatchesAKeyCarryingADecliningCharacter(): void
    {
        self::assertSame([], self::spanText("\"description\": one two\n", ['description']));
        self::assertSame([], self::spanText("a!b: one two\n", ['a!b']));
    }

    public function testAColonNotFollowedByASpaceIsAnOrdinaryKeyCharacter(): void
    {
        self::assertSame(['one two'], self::spanText("a:b: one two\n", ['a:b']));
        self::assertSame([], self::spanText("a:b: one two\n", ['a']));
    }

    public function testTheThreeScalarForms(): void
    {
        self::assertSame(
            ['one two', 'three four', 'five six'],
            self::spanText("a: one two\nb: \"three four\"\nc: |\n  five six\n"),
        );
    }

    public function testBlockSequenceEntriesAreConsumedAndNest(): void
    {
        self::assertSame(['one two', 'three four'], self::spanText("- a: one two\n- - b: three four\n"));
    }

    public function testANestedNodeIsScannedButAContinuationIsNot(): void
    {
        self::assertSame(['one two'], self::spanText("a:\n  b: one two\n"));
        self::assertSame([], self::spanText("a: inline value\n  b: one two\n"));
    }

    /**
     * modes.md 3.8.3 and 3.8.4 step 7: a construct the scan does not recognise yields no spans,
     * and an inline value's continuation lines are consumed rather than rescanned.
     *
     * @return iterable<string, array{string}>
     */
    public static function unclaimedProvider(): iterable
    {
        yield 'a bare sequence item' => ["- Some prose here...\n"];
        yield 'a flow sequence' => ["a: [one two..., three]\n"];
        yield 'a flow mapping' => ["a: {b: one two...}\n"];
        yield 'an anchor' => ["a: &anchor one two...\n"];
        yield 'an alias' => ["a: *anchor\n"];
        yield 'a tag' => ["a: !!str one two...\n"];
        yield 'a double-quoted scalar with an escape' => ["a: \"one \\\"two\\\"... three\"\n"];
        yield 'a single-quoted scalar with an escape' => ["a: 'it''s one two...'\n"];
        yield 'a tab anywhere on the line' => ["a:\tone two...\n"];
        yield 'a compact nested sequence' => ["a: - one two...\n"];
        yield 'a compact nested mapping' => ["a: one two .:\n"];
        yield 'an unterminated quoted scalar' => ["a: \"one two...\n"];
        yield 'a value that is only a comment' => ["a: # one two...\n"];
        yield 'a multi-line quoted scalar' => ["a: \"hello\n  b: some prose \"word\" here\"\n"];
        yield 'a multi-line flow mapping' => ["a: {\n  b: hello world,\n  c: x\n}\n"];
        yield 'a multi-line plain scalar' => ["a: one two...\n  b: three four...\n"];
        yield 'a document marker introducing a node' => ["--- a: one two\n"];
        yield 'a block with an ambiguous indicator' => ["a: |4\n  one two\n"];
        yield 'a block with a tab on a content line' => ["a: |\n  one two\n  three\tfour\n"];
        yield 'a block dedented inside itself' => ["a: |\n    deep one two\n  shallow three\n"];
        yield 'a block with an unrecognised header' => ["a: |x\n  one two\n"];
        yield 'a file that is not YAML at all' => ["{{ not yaml at all ... }}\n\t\tmixed\tindentation\n"];
    }

    #[DataProvider('unclaimedProvider')]
    public function testYieldsNoSpansAndReturnsBytesUnchanged(string $source): void
    {
        self::assertSame([], self::spanText($source));
        self::assertSame($source, self::yaml($source));
    }

    /** @return iterable<string, array{string}> */
    public static function chompingProvider(): iterable
    {
        foreach (['|', '|-', '|+', '>', '>-', '>+'] as $header) {
            yield $header => [$header];
        }
    }

    #[DataProvider('chompingProvider')]
    public function testEveryChompingIndicatorIsHandledIdentically(string $header): void
    {
        $source = "a: {$header}\n  one two...\n\n\nz: 1\n";
        self::assertSame(['one two...'], self::spanText($source));
        self::assertSame("a: {$header}\n  one two…\n\n\nz: 1\n", self::yaml($source));
    }

    public function testBlockScalarIndentationAndHeaderComment(): void
    {
        self::assertSame(['one two', '  three four'], self::spanText("a: |\n  one two\n    three four\n"));
        self::assertSame([' one two'], self::spanText("a: |2\n   one two\n"));
        self::assertSame(['one two'], self::spanText("a: | # note\n  one two\n"));
        self::assertSame(
            "a: |\n  one two\n    three four\n",
            self::yaml("a: |\n  one two\n    three  four\n"),
        );
    }

    public function testQuotesPairAcrossBlockScalarLines(): void
    {
        self::assertSame(
            "a: |\n  He said “hi”\n  and left\n",
            self::yaml("a: |\n  He said \"hi\"\n  and left\n"),
        );
    }

    public function testCrlfYieldsTheSameSpansAsLfAndKeepsItsCarriageReturns(): void
    {
        self::assertSame(['one two...'], self::spanText("a: |\r\n  one two...\r\n"));
        self::assertSame("a: |\r\n  one two…\r\n", self::yaml("a: |\r\n  one two...\r\n"));
    }

    public function testNoColonTestAppliesToAQuotedScalar(): void
    {
        self::assertSame(['Chapter 1: the beginning'], self::spanText("a: \"Chapter 1: the beginning\"\n"));
        self::assertSame(['some prose'], self::spanText("a: some prose # note: here\n"));
    }

    public function testThePlainScalarSplitsAtAColonAndAtAHash(): void
    {
        self::assertSame(['one', 'two three'], self::spanText("a: one:two three\n"));
        self::assertSame(['one', 'two three'], self::spanText("a: one#two three\n"));
    }

    public function testTheSplitDeclinesAGrowingReplacement(): void
    {
        foreach (["k: a:--b\n", "k: a--#b\n", "k: a:---b\n", "k: a---#b\n"] as $source) {
            self::assertSame($source, self::yaml($source, 'de-DE', ['k']), $source);
        }
    }

    public function testAContractionAtTheSamePositionStillApplies(): void
    {
        self::assertSame("k: a—#b\n", self::yaml("k: a--#b\n", 'en-US', ['k']));
        self::assertSame("k: a:—b\n", self::yaml("k: a:--b\n", 'en-US', ['k']));
    }

    public function testTheSpanPartitionIsStableBetweenRuns(): void
    {
        // modes.md 5 item 2: the predicate that selects a span reads the key, which no rule can
        // reach, so this is a fixed point.
        $source = "a: \${{ steps.pin.outputs.sha }}\n";
        $once = self::yaml($source);
        self::assertCount(count(self::spanText($source)), self::spanText($once));
        self::assertSame($once, self::yaml($once));
    }

    public function testTheRoundTripGuarantee(): void
    {
        $source = "a: one two\nb: 'it''s'\nc: [x, y]\n#comment\n";
        self::assertSame($source, self::yaml($source));
        self::assertSame("a: \"Une note… précise\"\n", self::yaml("a: \"Une note... précise\"\n", 'fr'));

        $changing = "a: The \"book\"... and more\nb: |\n  He said -- loudly\n";
        $once = self::yaml($changing);
        self::assertNotSame($changing, $once);
        self::assertSame($once, self::yaml($once));
    }
}
