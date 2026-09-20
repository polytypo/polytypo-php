<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;

/**
 * spec/rules/modes.md 3.4 -- the edge-growth rule's character clause (spec 1.3.0).
 *
 * Its normative sentence has always been "an edit is discarded if it would place code points at an
 * extremity of its span that were not there before". The formalisation `r > d` is not that rule:
 * it misses `r === d`. `dashes` P3 admits a run of two OR THREE, so `---` -> U+0020 en-dash
 * U+0020 is 3 -> 3 and lands U+0020 on both extremities while the length test sees nothing. In
 * `html`, shipped at v1.0.0, that produced an element beginning and ending with a space it never
 * held.
 */
final class EdgeGrowthTest extends TestCase
{
    /**
     * Read from the locale data rather than named here: a hardcoded list rots silently as locales
     * are added, and it would also admit `el`, whose `dash.parenthetical` is "none".
     *
     * @return iterable<string, array{string}>
     */
    public static function spacedLocaleProvider(): iterable
    {
        foreach (glob(__DIR__ . '/../resources/spec/locales/*.json') ?: [] as $path) {
            if (basename($path) === 'registry.json') {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            /** @var array{dash?: array{parenthetical?: string}} $data */
            $data = json_decode($raw, true);
            if (str_ends_with($data['dash']['parenthetical'] ?? 'none', '-spaced')) {
                yield basename($path, '.json') => [basename($path, '.json')];
            }
        }
    }

    #[DataProvider('spacedLocaleProvider')]
    public function testThreeDashesAtASpanEdgeAreDeclined(string $locale): void
    {
        self::assertSame('a<em>---</em>b', Polytypo::transform('a<em>---</em>b', $locale, 'html'));
    }

    public function testTheSameEditInteriorToASpanStillApplies(): void
    {
        self::assertSame('<p>a – b</p>', Polytypo::transform('<p>a---b</p>', 'de-DE', 'html'));
    }

    public function testAnEmTightLocaleStillConvertsAtTheEdge(): void
    {
        // It emits no U+0020 at all, so the character clause does not fire.
        self::assertSame('a<em>—</em>b', Polytypo::transform('a<em>---</em>b', 'en-US', 'html'));
    }

    public function testASpacedEditReplacingASpaceWithASpaceStillApplies(): void
    {
        self::assertSame('a<em>x – y</em>b', Polytypo::transform('a<em>x --- y</em>b', 'de-DE', 'html'));
    }

    public function testTwoDashesAtAnEdgeAreStillDeclinedByTheLengthClause(): void
    {
        self::assertSame('a<em>--</em>b', Polytypo::transform('a<em>--</em>b', 'de-DE', 'html'));
    }
}
