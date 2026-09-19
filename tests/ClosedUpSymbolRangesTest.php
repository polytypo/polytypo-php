<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;

/**
 * spec/rules/ranges.md §3.2a (spec 1.3.0) -- a symbol repeated closed up on both members, and the
 * dashes.md §3.2 step 8 amendment it forced.
 */
final class ClosedUpSymbolRangesTest extends TestCase
{
    private const WJ = "\u{2060}";
    private const EN = "\u{2013}";
    private const EM = "\u{2014}";

    private static function ranges(string $input, string $locale = 'en-US'): string
    {
        return Polytypo::transform($input, $locale, 'text', null, ['ranges' => true]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function candidacyCases(): array
    {
        $wj = self::WJ;
        $en = self::EN;
        $em = self::EM;

        return [
            'repeated prefix' => ['en-US', '$15-$20', "\$15{$wj}{$en}{$wj}\$20"],
            'repeated prefix, euro' => ['de-DE', '€15-€20', "€15{$wj}{$en}{$wj}€20"],
            'repeated suffix' => ['en-US', '35%-50%', "35%{$wj}{$en}{$wj}50%"],
            'repeated suffix, em locale' => ['ru', '35%-50%', "35%{$wj}{$em}{$wj}50%"],
            'degree sign' => ['en-US', '15°-20°', "15°{$wj}{$en}{$wj}20°"],
            'mismatched symbols decline' => ['en-US', '$15-€20', '$15-€20'],
            'half-written declines' => ['en-US', '15-$20', '15-$20'],
            'half-written suffix declines' => ['en-US', '15%-20', '15%-20'],
            'elided prefix still converts' => ['en-US', '$15-20', "\$15{$wj}{$en}{$wj}20"],
            'elided suffix still converts' => ['en-US', '15-20%', "15{$wj}{$en}{$wj}20%"],
            'multi-code-point prefix declines' => ['en-US', 'US$15-US$20', 'US$15-US$20'],
            'multi-code-point suffix declines' => ['en-US', '15°C-20°C', '15°C-20°C'],
            'both flanks decided together' => ['en-US', '%15%-%20%', "%15%{$wj}{$en}{$wj}%20%"],
            'spaced token is now a range' => ['en-US', '$15 - $20', "\$15{$wj}{$en}{$wj}\$20"],
            'hyphen between a joiner pair' => ['en-US', "\$15{$wj}-{$wj}\$20", "\$15{$wj}{$en}{$wj}\$20"],
        ];
    }

    #[DataProvider('candidacyCases')]
    public function testCandidacy(string $locale, string $input, string $want): void
    {
        $this->assertSame($want, self::ranges($input, $locale));
    }

    /**
     * CLOSED-SYMBOL is the U+20A0-U+20CF block by its bounds plus a fixed list, never an Sc
     * category test: a port enumerating only the symbols it saw in tests fails the first two, and
     * a port using a category test fails the last two.
     */
    public function testSymbolSetBoundary(): void
    {
        $wj = self::WJ;
        $en = self::EN;
        $this->assertSame("₹15{$wj}{$en}{$wj}₹20", self::ranges('₹15-₹20'));
        $this->assertSame("₴100{$wj}{$en}{$wj}₴200", self::ranges('₴100-₴200'));
        $this->assertSame('֏15-֏20', self::ranges('֏15-֏20'));
        $this->assertSame('￥15-￥20', self::ranges('￥15-￥20'));
    }

    /**
     * dashes.md §3.2 step 8: widening a range member widens what a `dashes` edit elsewhere can
     * disturb. Each witness drifted on the second pass before T1's reach became
     * CLOSED-SYMBOL-transparent, and each is inert in its all-digit shape.
     */
    public function testT1IsAFixedPointOnEveryWitness(): void
    {
        $witnesses = ['a—$15-$20', '35%-50%—b', 'a--15% - 20%', '$1 - $1--a'];
        foreach (['de-DE', 'ru', 'en-GB', 'fi'] as $locale) {
            foreach ($witnesses as $input) {
                $once = self::ranges($input, $locale);
                $this->assertSame($once, self::ranges($once, $locale), "{$locale} {$input}");
            }
        }

        // Both transparency positions on one side at once -- eleven tokens, out of reach of the
        // canonical exhaustive sweep.
        foreach (['a--$15% - $20%', '$15% - $20%--a', 'a--$15% - $20%--b'] as $input) {
            $once = self::ranges($input, 'de-DE');
            $this->assertSame($once, self::ranges($once, 'de-DE'), $input);
        }
    }

    public function testT1Boundary(): void
    {
        // T1 applies only when the chosen form is spaced, which is why the repair is T1 and not
        // the unconditional cluster guard: that one would have taken en-US with it.
        $this->assertSame('price—$50—drop', Polytypo::transform('price--$50--drop', 'en-US'));
        $this->assertSame('Anstieg--50%--war', Polytypo::transform('Anstieg--50%--war', 'de-DE'));
        $this->assertSame('Anstieg--50--war', Polytypo::transform('Anstieg--50--war', 'de-DE'));

        // The accepted cost, with default options, and its limit: only a MATCHED symbol moves a
        // token between rules.
        $this->assertSame('$15 - $20', Polytypo::transform('$15 - $20', 'en-US'));
        $this->assertSame('$15—€20', Polytypo::transform('$15 - €20', 'en-US'));
    }

    public function testRightBranchReadsEffectiveNeighbours(): void
    {
        // dashes.md §3.2 step 8 disagreed with §3.2b in its own text through spec 1.2.0; no
        // implementation ever did. The space ends the cluster, so step 7 does not cover this.
        $wj = self::WJ;
        $this->assertSame("a--15{$wj} - 20", self::ranges("a--15{$wj} - 20", 'de-DE'));
        $this->assertSame("a--\$15{$wj}-{$wj}\$20", self::ranges("a--\$15{$wj}-{$wj}\$20", 'de-DE'));
    }
}
