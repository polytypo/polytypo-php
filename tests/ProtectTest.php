<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polytypo\Change;
use Polytypo\Engine\Codepoints;
use Polytypo\Engine\Pipeline;
use Polytypo\Engine\Protect;
use Polytypo\Engine\RuleContext;
use Polytypo\Polytypo;
use Polytypo\PolytypoException;

/**
 * modes.md 3.9 (spec 1.13.0). The fixtures cover the observable behaviour through ConformanceTest;
 * this file covers what a fixture cannot say: the validation order against options a fixture
 * cannot vary, offsets in code points rather than bytes, the idempotency property under moved
 * ranges (3.9.5), and that analyze() never reports a range that reaches into text the rules did
 * not see (3.9.6).
 */
final class ProtectTest extends TestCase
{
    /** @return list<string> */
    private static function locales(): array
    {
        $registry = json_decode(
            file_get_contents(dirname(__DIR__) . '/resources/spec/locales/registry.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $registry['locales'];
    }

    private static function code(callable $call): string
    {
        try {
            $call();
        } catch (PolytypoException $e) {
            return $e->getErrorCode();
        }

        return 'none';
    }

    public function testTheWorkedExampleOfTheChangelog(): void
    {
        $range = [['start' => 0, 'end' => 4]];

        self::assertSame('${x}—Rules', Polytypo::transform('${x} - Rules', 'en-US', protect: $range));
        self::assertSame('${x} – Rules', Polytypo::transform('${x} - Rules', 'en-GB', protect: $range));
    }

    public function testAbsentNullAndEmptyListAreTheSameCall(): void
    {
        $input = "a -- b ... \"c\"";

        $absent = Polytypo::transform($input, 'en-US');
        self::assertSame($absent, Polytypo::transform($input, 'en-US', protect: null));
        self::assertSame($absent, Polytypo::transform($input, 'en-US', protect: []));
        self::assertEquals(Polytypo::analyze($input, 'en-US'), Polytypo::analyze($input, 'en-US', protect: []));
        self::assertSame('<p>a—b</p>', Polytypo::transform('<p>a -- b</p>', 'en-US', mode: 'html', protect: []));
    }

    public function testOffsetsAreCodePointsNotBytesOrUtf16Units(): void
    {
        // The emoji is four bytes, two UTF-16 units and one code point; the hole starts after it.
        $input = '😀 -- 😀${x} -- 😀';

        self::assertSame(
            '😀—😀${x}—😀',
            Polytypo::transform($input, 'en-US', protect: [['start' => 5, 'end' => 10]]),
        );
        // Byte offsets for the same hole would run past the end in code points.
        self::assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            self::code(static fn () => Polytypo::transform('😀', 'en-US', protect: [['start' => 0, 'end' => 2]])),
        );
        self::assertSame('😀', Polytypo::transform('😀', 'en-US', protect: [['start' => 0, 'end' => 1]]));
    }

    public function testTouchingRangesAreOneRun(): void
    {
        $input = '${a}${b} - x';
        $touching = Polytypo::transform($input, 'en-US', protect: [
            ['start' => 0, 'end' => 4], ['start' => 4, 'end' => 8],
        ]);
        $single = Polytypo::transform($input, 'en-US', protect: [['start' => 0, 'end' => 8]]);

        self::assertSame($single, $touching);
        self::assertSame('${a}${b}—x', $touching);
    }

    /** @return array<string, array{0: mixed}> */
    public static function malformed(): array
    {
        return [
            'not a list (string)' => ['0-4'],
            'not a list (int)' => [4],
            'not a list (bool)' => [true],
            'object-like map' => [['start' => 0, 'end' => 1]],
            'element not an array' => [[3]],
            'float end' => [[['start' => 0, 'end' => 1.5]]],
            'whole float' => [[['start' => 0.0, 'end' => 2.0]]],
            'numeric string' => [[['start' => '0', 'end' => '2']]],
            'bool' => [[['start' => false, 'end' => true]]],
            'null end' => [[['start' => 0, 'end' => null]]],
            'missing end' => [[['start' => 0]]],
            'missing start' => [[['end' => 2]]],
            'empty range' => [[['start' => 2, 'end' => 2]]],
            'reversed range' => [[['start' => 3, 'end' => 1]]],
            'negative' => [[['start' => -1, 'end' => 2]]],
            'past the end' => [[['start' => 4, 'end' => 7]]],
            'overlap' => [[['start' => 0, 'end' => 4], ['start' => 3, 'end' => 6]]],
            'descending' => [[['start' => 4, 'end' => 6], ['start' => 0, 'end' => 2]]],
            'duplicate' => [[['start' => 0, 'end' => 2], ['start' => 0, 'end' => 2]]],
        ];
    }

    #[DataProvider('malformed')]
    public function testEveryRefusalIsInvalidOptionInTransformAndAnalyze(mixed $protect): void
    {
        self::assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            self::code(static fn () => Polytypo::transform('a -- b', 'en-US', protect: $protect)),
        );
        self::assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            self::code(static fn () => Polytypo::analyze('a -- b', 'en-US', protect: $protect)),
        );
    }

    public function testUnrecognisedMembersOfARangeAreIgnored(): void
    {
        self::assertSame(
            '${x}—Rules',
            Polytypo::transform('${x} - Rules', 'en-US', protect: [['start' => 0, 'end' => 4, 'label' => 'hole']]),
        );
    }

    public function testANonEmptyListIsRefusedOutsideTextMode(): void
    {
        $range = [['start' => 0, 'end' => 1]];

        self::assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            self::code(static fn () => Polytypo::transform('<p>a -- b</p>', 'en-US', mode: 'html', protect: $range)),
        );
        self::assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            self::code(static fn () => Polytypo::analyze('<p>a -- b</p>', 'en-US', mode: 'html', protect: $range)),
        );
        self::assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            self::code(static fn () => Polytypo::transform(
                "title: a -- b\n",
                'en-US',
                mode: 'yaml',
                keys: ['title'],
                protect: $range,
            )),
        );
        self::assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            self::code(static fn () => Polytypo::analyze(
                "title: a -- b\n",
                'en-US',
                mode: 'yaml',
                keys: ['title'],
                protect: $range,
            )),
        );
    }

    public function testValidationOrderPutsProtectAfterEveryEarlierOption(): void
    {
        $bad = [['start' => 3, 'end' => 2]];
        $some = [['start' => 0, 'end' => 1]];

        // mode, then narrowNbsp, then rules, then locale.
        self::assertSame(
            PolytypoException::CODE_INVALID_MODE,
            self::code(static fn () => Polytypo::transform('a', 'en-US', mode: 'nope', narrowNbsp: 'x', protect: $bad)),
        );
        self::assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            self::code(static fn () => Polytypo::transform('a', 'xx', narrowNbsp: 'x', protect: $bad)),
        );
        self::assertSame(
            PolytypoException::CODE_UNKNOWN_RULE,
            self::code(static fn () => Polytypo::transform('a -- b', 'xx', rules: ['nope' => true], protect: $bad)),
        );
        self::assertSame(
            PolytypoException::CODE_UNKNOWN_LOCALE,
            self::code(static fn () => Polytypo::transform('a -- b', 'xx', protect: $bad)),
        );
        self::assertSame(
            PolytypoException::CODE_UNKNOWN_LOCALE,
            self::code(static fn () => Polytypo::analyze('a -- b', 'xx', mode: 'html', protect: $some)),
        );
        // dialect and keys come before protect.
        self::assertSame(
            PolytypoException::CODE_INVALID_DIALECT,
            self::code(static fn () => Polytypo::transform('a -- b', 'en-US', dialect: 'commonmark', protect: $bad)),
        );
        self::assertSame(
            PolytypoException::CODE_INVALID_DIALECT,
            self::code(static fn () => Polytypo::transform('a -- b', 'en-US', mode: 'markdown', dialect: 'commonmark', protect: $some)),
        );
        self::assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            self::code(static fn () => Polytypo::transform('title: a', 'en-US', mode: 'yaml', protect: $some)),
        );
    }

    public function testAProtectedRunIsReturnedByteForByte(): void
    {
        $input = "x  \u{2028}  y " . '${a  --  b}' . '  z';
        $range = [['start' => 8, 'end' => 19]];
        $hole = '${a  --  b}';

        self::assertStringContainsString($hole, Polytypo::transform($input, 'en-US', protect: $range));
        self::assertSame($input, Polytypo::transform($input, 'en-US', protect: [['start' => 0, 'end' => mb_strlen($input, 'UTF-8')]]));
    }

    public function testALineTerminatorInsideARunMakesItALineMarker(): void
    {
        foreach (["\n", "\r", "\u{000B}", "\u{000C}", "\u{0085}", "\u{2028}", "\u{2029}"] as $terminator) {
            $input = 'x ${' . $terminator . 'y} -- z';
            $range = [['start' => 2, 'end' => 7]];
            // The dash's left neighbour is a line break, so the token is declined.
            self::assertSame($input, Polytypo::transform($input, 'en-US', protect: $range), bin2hex($terminator));
        }
        self::assertSame('x ${ y}—z', Polytypo::transform('x ${ y} -- z', 'en-US', protect: [['start' => 2, 'end' => 7]]));
    }

    /**
     * @return array{0: string, 1: list<array{0: int, 1: int}>, 2: string}
     */
    private static function template(int $seed): array
    {
        mt_srand($seed);
        $tokens = [
            'a', 'Rules', ' ', ' ', ' ', '-', '--', '---', '...', '.', ',', '!', '?', ':', ';', "\u{2013}",
            "\u{2014}", '"', "'", "\u{201C}", "\u{201D}", '(', ')', "\n", "'s", "n't", 'km', '%', '😀', 'é',
            '«', '»', '„', '12', '1-2',
        ];
        $holes = ['${x}', '{n}', '<b>', '{a, plural, one {#}}', "'{b}'", "😀\u{2028}"];
        $count = mt_rand(3, 10);
        $text = '';
        $ranges = [];
        $cursor = 0;
        for ($i = 0; $i < $count; $i++) {
            if (mt_rand(0, 4) === 0) {
                $piece = $holes[mt_rand(0, count($holes) - 1)];
                $length = mb_strlen($piece, 'UTF-8');
                $ranges[] = [$cursor, $cursor + $length];
                $text .= $piece;
                $cursor += $length;
            } else {
                $piece = $tokens[mt_rand(0, count($tokens) - 1)];
                $text .= $piece;
                $cursor += mb_strlen($piece, 'UTF-8');
            }
        }

        return [$text, $ranges, 'seed' . $seed];
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     * @return list<array{start: int, end: int}>
     */
    private static function asOption(array $ranges): array
    {
        return array_map(static fn (array $r): array => ['start' => $r[0], 'end' => $r[1]], $ranges);
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     * @return array{0: string, 1: list<array{start: int, end: int}>}
     */
    private static function runMapped(string $input, string $locale, array $ranges): array
    {
        $cp = Codepoints::toCodepoints($input);
        $holes = Protect::resolve(self::asOption($ranges), count($cp));
        [$resolvedLocale, $localeData, $plan] = Pipeline::prepare($locale, null);
        $ctx = new RuleContext(mode: 'text', dialect: null, locale: $resolvedLocale, narrowTarget: 0x202F);
        [$out, $mapped] = Protect::runMapped($cp, $holes, $plan, $localeData, $ctx);

        return [Codepoints::fromCodepoints($out), $mapped];
    }

    /** modes.md 3.9.5: transform(out, protect') = out, protect' located by the first call. */
    public function testIdempotencyHoldsUnderMovedRanges(): void
    {
        $failures = [];
        $documents = 0;
        foreach (self::locales() as $localeIndex => $locale) {
            for ($n = 0; $n < 90; $n++) {
                [$input, $ranges, $label] = self::template($localeIndex * 1000 + $n);
                [$out, $mapped] = self::runMapped($input, $locale, $ranges);
                $documents++;

                $direct = Polytypo::transform($input, $locale, protect: self::asOption($ranges));
                if ($direct !== $out) {
                    $failures[] = "{$locale} {$label}: transform and the mapped run disagree";
                    continue;
                }
                $outCp = Codepoints::toCodepoints($out);
                $inCp = Codepoints::toCodepoints($input);
                $runs = Protect::resolve(self::asOption($ranges), count($inCp));
                foreach ($runs as $i => [$start, $end]) {
                    $moved = array_slice($outCp, $mapped[$i]['start'], $mapped[$i]['end'] - $mapped[$i]['start']);
                    if ($moved !== array_slice($inCp, $start, $end - $start)) {
                        $failures[] = "{$locale} {$label}: hole {$i} changed";
                    }
                }
                $again = Polytypo::transform($out, $locale, protect: $mapped);
                if ($again !== $out) {
                    $failures[] = "{$locale} {$label}: not idempotent " . json_encode($input);
                }
            }
        }

        self::assertGreaterThan(1000, $documents);
        self::assertSame([], array_slice($failures, 0, 5));
    }

    /**
     * @param Change[] $changes
     * @param list<array{0: int, 1: int}> $holes
     */
    private static function overlapsAHole(array $changes, array $holes): bool
    {
        foreach ($changes as $change) {
            foreach ($holes as [$start, $end]) {
                $reaches = max($change->start, $start) < min($change->end, $end);
                $insideInsertion = $change->start === $change->end && $start < $change->start && $change->start < $end;
                if ($reaches || $insideInsertion) {
                    return true;
                }
            }
        }

        return false;
    }

    public function testAnalyzeNeverReportsARangeThatReachesIntoAHole(): void
    {
        $failures = [];
        foreach (self::locales() as $localeIndex => $locale) {
            for ($n = 0; $n < 60; $n++) {
                [$input, $ranges, $label] = self::template($localeIndex * 1000 + $n + 500);
                $length = mb_strlen($input, 'UTF-8');
                $changes = Polytypo::analyze($input, $locale, protect: self::asOption($ranges));

                if (self::overlapsAHole($changes, $ranges)) {
                    $failures[] = "{$locale} {$label}: a range reaches into a hole " . json_encode($input);
                }
                foreach ($changes as $change) {
                    // A5: offsets lie inside the input as passed.
                    if ($change->start < 0 || $change->start > $change->end || $change->end > $length) {
                        $failures[] = "{$locale} {$label}: offsets out of bounds";
                    }
                }
                // A3: the list is empty exactly when transform leaves the text alone.
                $unchanged = Polytypo::transform($input, $locale, protect: self::asOption($ranges)) === $input;
                if ($unchanged !== ($changes === [])) {
                    $failures[] = "{$locale} {$label}: A3 " . json_encode($input);
                }
            }
        }

        self::assertSame([], array_slice($failures, 0, 5));
    }

    public function testAnalyzeOffsetsAreCodePointsWithAnAstralCharacterBeforeAndInsideAHole(): void
    {
        $input = '😀 -- 😀${x} -- 😀';
        $changes = Polytypo::analyze($input, 'en-US', protect: [['start' => 5, 'end' => 10]]);

        $dashes = array_values(array_filter($changes, static fn (Change $c): bool => $c->ruleId === 'dashes'));
        self::assertCount(2, $dashes);
        self::assertSame([1, 5], [$dashes[0]->start, $dashes[0]->end]);
        self::assertSame([10, 14], [$dashes[1]->start, $dashes[1]->end]);
        self::assertFalse(self::overlapsAHole($changes, [[5, 10]]));
    }

    public function testAnalyzeEndStopsAtTheLastReplacedCodePointAcrossASkippedElement(): void
    {
        // Released 1.12.0 scanned forward to the next code point with an origin and reported
        // start 4, end 21 here, across the whole <code> element.
        $changes = Polytypo::analyze('<p>x--<code>cc</code>y</p>', 'en-US', mode: 'html');

        $dash = array_values(array_filter($changes, static fn (Change $c): bool => $c->ruleId === 'dashes'));
        self::assertCount(1, $dash);
        self::assertSame(4, $dash[0]->start);
        self::assertSame(6, $dash[0]->end);
        self::assertSame('--', $dash[0]->before);
    }

    public function testAnalyzeEndAcrossAProtectedRun(): void
    {
        $changes = Polytypo::analyze('x--${a}y', 'en-US', protect: [['start' => 3, 'end' => 7]]);

        $dash = array_values(array_filter($changes, static fn (Change $c): bool => $c->ruleId === 'dashes'));
        self::assertCount(1, $dash);
        self::assertSame([1, 3], [$dash[0]->start, $dash[0]->end]);
    }

    public function testNoHtmlRangeReachesIntoASkippedElement(): void
    {
        $documents = [
            '<p>x--<code>cc</code>y</p>',
            '<p>a...<code>b...</code>...c</p>',
            '<p>"a<code>q</code>b" -- <script>x -- y</script> -- z</p>',
            '<p>1 - 2 <pre>x  --  y</pre> and  more</p>',
        ];
        foreach ($documents as $html) {
            foreach (Polytypo::analyze($html, 'en-US', mode: 'html') as $change) {
                $covered = mb_substr($html, $change->start, $change->end - $change->start, 'UTF-8');
                self::assertStringNotContainsString('<', $covered, $html);
                self::assertStringNotContainsString('>', $covered, $html);
            }
        }
    }

    public function testTransformOutputIsUnchangedByTheAnalyzeFix(): void
    {
        self::assertSame(
            '<p>x—<code>cc</code>y</p>',
            Polytypo::transform('<p>x--<code>cc</code>y</p>', 'en-US', mode: 'html'),
        );
    }
}
