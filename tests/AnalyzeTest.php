<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\TestCase;
use Polytypo\Change;
use Polytypo\Polytypo;
use Polytypo\PolytypoException;

/**
 * spec/rules/analyze.md -- the contract is A1...A5; the decomposition is observation.
 *
 * Mirrors the JS, Python, Go and Ruby ports' own analyze suites case for case, including the two
 * that are cheap to get wrong (analyze.md section 6): A3 over the whole vendored corpus, and
 * document offsets under the mode adapter. This runtime implements no markdown dialect, so
 * analyze() rejects markdown exactly as transform() does -- which is itself A1.
 */
final class AnalyzeTest extends TestCase
{
    private const RULE_ORDER = [
        'spaces', 'ellipsis', 'ranges', 'dashes', 'hyphen', 'quotes', 'apostrophe', 'symbols', 'nbsp',
    ];

    /**
     * @param Change[] $changes
     * @return string[]
     */
    private static function ruleIds(array $changes): array
    {
        return array_map(static fn (Change $c): string => $c->ruleId, $changes);
    }

    public function testRejectsAnUnknownLocaleLikeTransform(): void
    {
        try {
            Polytypo::analyze('x', 'xx');
            $this->fail('expected an exception');
        } catch (PolytypoException $e) {
            $this->assertSame(PolytypoException::CODE_UNKNOWN_LOCALE, $e->getErrorCode());
        }
    }

    public function testAnUnknownRuleWinsOverAnUnknownLocale(): void
    {
        try {
            Polytypo::analyze('x', 'xx', rules: ['nope' => true]);
            $this->fail('expected an exception');
        } catch (PolytypoException $e) {
            $this->assertSame(PolytypoException::CODE_UNKNOWN_RULE, $e->getErrorCode());
        }
    }

    public function testRejectsAnUnknownMode(): void
    {
        try {
            Polytypo::analyze('x', 'en-US', 'yaml');
            $this->fail('expected an exception');
        } catch (PolytypoException $e) {
            $this->assertSame(PolytypoException::CODE_INVALID_MODE, $e->getErrorCode());
        }
    }

    public function testRejectsMarkdownExactlyAsTransformDoes(): void
    {
        try {
            Polytypo::analyze('x', 'en-US', 'markdown', 'commonmark');
            $this->fail('expected an exception');
        } catch (PolytypoException $e) {
            $this->assertSame(PolytypoException::CODE_INVALID_DIALECT, $e->getErrorCode());
        }
    }

    public function testRejectsADialectOutsideMarkdown(): void
    {
        try {
            Polytypo::analyze('x', 'en-US', 'text', 'commonmark');
            $this->fail('expected an exception');
        } catch (PolytypoException $e) {
            $this->assertSame(PolytypoException::CODE_INVALID_DIALECT, $e->getErrorCode());
        }
    }

    public function testIsPure(): void
    {
        $input = 'She said "hi" -- really...';
        $before = Polytypo::transform($input, 'en-US');

        $once = Polytypo::analyze($input, 'en-US');
        $twice = Polytypo::analyze($input, 'en-US');
        $this->assertEquals($once, $twice);

        $this->assertSame($before, Polytypo::transform($input, 'en-US'));
    }

    public function testIsEmptyForTextThatNeedsNothing(): void
    {
        $this->assertSame([], Polytypo::analyze('Nothing to do here.', 'en-US'));
    }

    public function testIsNonEmptyForTextThatNeedsSomething(): void
    {
        $this->assertNotSame([], Polytypo::analyze('Wait...', 'en-US'));
    }

    /** analyze.md section 6: the whole vendored corpus, the cheap strong version of A3. */
    public function testAgreesWithTransformOnEveryFixture(): void
    {
        $offenders = [];
        foreach (ConformanceTest::cases() as $key => [$basename, $case]) {
            if (isset($case['throws']) || $case['mode'] === 'markdown') {
                continue;
            }
            $locale = $case['locale'];
            $rules = $case['rules'] ?? null;
            $changed = Polytypo::transform($case['in'], $locale, $case['mode'], null, $rules) !== $case['in'];
            $reported = Polytypo::analyze($case['in'], $locale, $case['mode'], null, $rules) !== [];
            if ($changed !== $reported) {
                $offenders[] = $key;
            }
        }
        $this->assertSame([], $offenders);
    }

    public function testNeverReportsARuleTheCallerDisabled(): void
    {
        $ids = self::ruleIds(Polytypo::analyze('She said "hi"...', 'en-US', rules: ['quotes' => false]));
        $this->assertNotContains('quotes', $ids);
        $this->assertContains('ellipsis', $ids);
    }

    public function testNeverReportsRangesUnlessTurnedOn(): void
    {
        $input = 'chapters 3-5';
        $this->assertNotContains('ranges', self::ruleIds(Polytypo::analyze($input, 'en-US')));
        $this->assertContains(
            'ranges',
            self::ruleIds(Polytypo::analyze($input, 'en-US', rules: ['ranges' => true])),
        );
    }

    public function testStaysWithinBoundsOnAstralInput(): void
    {
        $input = 'A 😀 says "hi" and waits...';
        $length = mb_strlen($input, 'UTF-8');
        foreach (Polytypo::analyze($input, 'en-US') as $change) {
            $this->assertGreaterThanOrEqual(0, $change->start);
            $this->assertLessThanOrEqual($length, $change->end);
            $this->assertLessThanOrEqual($change->end, $change->start);
        }
    }

    public function testReportsCodePointOffsetsNotByteOffsets(): void
    {
        // The emoji is four UTF-8 bytes and one code point, so the opening quotation mark sits at
        // code point 6 and at byte index 9. A runtime reporting native string offsets -- which in
        // PHP means bytes -- says 9.
        $input = '😀 and "this"';
        $changes = Polytypo::analyze($input, 'en-US');
        $this->assertNotSame([], $changes);
        $this->assertSame('quotes', $changes[0]->ruleId);
        $this->assertSame(6, $changes[0]->start);
        $this->assertSame(9, strpos($input, '"'), 'test premise: the byte offset differs from the code-point one');
    }

    /** analyze.md section 6: the mistake that passes every text-mode test. */
    public function testHtmlModeReportsDocumentOffsets(): void
    {
        $input = '<p class="x">Wait...</p>';
        $changes = Polytypo::analyze($input, 'en-US', 'html');
        $this->assertNotSame([], $changes);
        $this->assertSame('ellipsis', $changes[0]->ruleId);
        $this->assertSame(mb_strpos($input, '...', 0, 'UTF-8'), $changes[0]->start);
        $this->assertSame('...', $changes[0]->before);
        $this->assertSame("\u{2026}", $changes[0]->after);
    }

    public function testHtmlModeReportsDocumentOffsetsInALaterSpan(): void
    {
        // Three spans, and the change is in the third: the two markers before it are the only
        // code points in the joined array with no origin, so a doubled or dropped one shifts this
        // offset and nothing in a one- or two-span document would notice.
        $input = '<p>one</p><p>two</p><p>Wait... three</p>';
        $changes = Polytypo::analyze($input, 'en-US', 'html');
        $this->assertNotSame([], $changes);
        $this->assertSame('ellipsis', $changes[0]->ruleId);
        $this->assertSame(mb_strpos($input, '...', 0, 'UTF-8'), $changes[0]->start);
    }

    public function testReportsRulesInPipelineOrder(): void
    {
        $ids = self::ruleIds(Polytypo::analyze('She said "hi" -- wait...', 'en-US'));
        $sorted = $ids;
        usort($sorted, static fn (string $a, string $b): int
            => array_search($a, self::RULE_ORDER, true) <=> array_search($b, self::RULE_ORDER, true));
        $this->assertSame($sorted, $ids);
    }

    public function testReportsBothRulesOnTheSameOriginalRange(): void
    {
        // The French case analyze.md section 5 is written around: two rules, one original index.
        // `spaces` removes the space at 3-4 and `nbsp` inserts at 4-4, in front of the colon the
        // caller wrote at 4 -- the second change's position is the colon's, not the deleted
        // space's.
        $changes = Polytypo::analyze('Oui : non', 'fr');
        $this->assertSame(['spaces', 'nbsp'], self::ruleIds($changes));
        $this->assertSame(' ', $changes[0]->before);
        $this->assertSame('', $changes[0]->after);
        $this->assertSame([3, 4], [$changes[0]->start, $changes[0]->end]);
        $this->assertSame("\u{00a0}", $changes[1]->after);
        $this->assertSame([4, 4], [$changes[1]->start, $changes[1]->end]);
    }
}
