<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;

/**
 * transform(transform(x)) === transform(x) is a release blocker, not a bug report
 * (docs/ARCHITECTURE.md section 6.3). Property-based over a biased alphabet (uniform random
 * Unicode almost never produces the adjacent-quote-mark shapes that actually break a pipeline),
 * plus bounded exhaustive sweeps, which a defect at this size cannot hide from. Mirrors the other
 * four ports' own idempotency test suites.
 */
final class IdempotencyTest extends TestCase
{
    use TestTrait;

    /** @return string[] */
    private static function locales(): array
    {
        $registry = json_decode(
            file_get_contents(dirname(__DIR__) . '/resources/spec/locales/registry.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $registry['locales'];
    }

    /** The characters every rule reads: quote marks, strokes, spacing, digits, brackets, stops. */
    private static function hotChars(): array
    {
        return [
            '"', "'", '“', '”', '‘', '’', '„', '‚',
            '«', '»', '‹', '›',
            '-', '‐', '‑', '–', '—', ' ', "\u{00A0}", "\u{202F}", "\n",
            '.', ',', ':', ';', '!', '?', '…', '(', ')', '[', ']',
            '0', '1', '9', 'a', 'B', 'x', 'é', 'и', 'k', 'm', '%', '§', '№', 'σ', '·',
        ];
    }

    private static function joinedSeq(array $alphabet): \Eris\Generator
    {
        return Generators::map(
            static fn (array $chars): string => implode('', $chars),
            Generators::seq(Generators::elements(...$alphabet)),
        );
    }

    #[Test]
    public function isIdempotentOverAHotAlphabet(): void
    {
        $this->forAll(
            Generators::elements(...self::locales()),
            self::joinedSeq(self::hotChars()),
        )->then(function (string $locale, string $text): void {
            $once = Polytypo::transform($text, $locale);
            $twice = Polytypo::transform($once, $locale);
            self::assertSame($once, $twice, "locale={$locale} input=" . json_encode($text));
        });
    }

    #[Test]
    public function isIdempotentOverArbitraryPrintableAsciiPlusAFewUnicodeLetters(): void
    {
        $chars = array_merge(array_map('chr', range(0x20, 0x7E)), ['é', 'и', '中']);

        $this->forAll(
            Generators::elements(...self::locales()),
            self::joinedSeq($chars),
        )->then(function (string $locale, string $text): void {
            $once = Polytypo::transform($text, $locale);
            $twice = Polytypo::transform($once, $locale);
            self::assertSame($once, $twice);
        });
    }

    #[Test]
    public function isANoOpWithEveryRuleDisabled(): void
    {
        $order = json_decode(
            file_get_contents(dirname(__DIR__) . '/resources/spec/rules/order.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $allOff = [];
        foreach ($order['rules'] as $r) {
            $allOff[$r['id']] = false;
        }

        $this->forAll(
            Generators::elements(...self::locales()),
            self::joinedSeq(self::hotChars()),
        )->then(function (string $locale, string $text) use ($allOff): void {
            $out = Polytypo::transform($text, $locale, rules: $allOff);
            self::assertSame($text, $out);
        });
    }

    /**
     * Both the input characters and the ones the rules produce: a pass over its own output is
     * what idempotency actually asserts.
     *
     * @param string[] $alphabet
     */
    private static function boundedStrings(array $alphabet, int $maxLength, callable $yield): void
    {
        $yield('');
        $frontier = [''];
        for ($n = 0; $n < $maxLength; $n++) {
            $nextFrontier = [];
            foreach ($frontier as $prefix) {
                foreach ($alphabet as $ch) {
                    $candidate = $prefix . $ch;
                    $nextFrontier[] = $candidate;
                    $yield($candidate);
                }
            }
            $frontier = $nextFrontier;
        }
    }

    /**
     * `)` is in this alphabet for apostrophe.md 3.3's case 2a (spec 1.5.0), under
     * pipeline-idempotency.md 6's standing obligation to widen the alphabet in the same change
     * that fixes a defect its bound cannot reach. It is the one CLOSEDELIM member the alphabet
     * did not already hold: `”` was in it as an emitted quote glyph and covers the quotation
     * half of the class.
     */
    #[Test]
    public function isIdempotentOverABoundedExhaustiveSweepEveryLocale(): void
    {
        $alphabet = ['"', "'", '-', ' ', '.', '1', 'a', '«', '–', '”', ')'];
        $broken = [];
        foreach (self::locales() as $locale) {
            self::boundedStrings($alphabet, 4, function (string $text) use ($locale, &$broken): void {
                if (count($broken) >= 10) {
                    return;
                }
                $once = Polytypo::transform($text, $locale);
                $twice = Polytypo::transform($once, $locale);
                if ($twice !== $once) {
                    $broken[] = "{$locale}: " . json_encode($text) . ' -> ' . json_encode($once) . ' -> ' . json_encode($twice);
                }
            });
        }
        self::assertSame([], $broken);
    }

    #[Test]
    public function isIdempotentForMixedKindStraightMarks(): void
    {
        $alphabet = ['"', "'", 'a', ' ', '.'];
        $broken = [];
        foreach (self::locales() as $locale) {
            self::boundedStrings($alphabet, 6, function (string $text) use ($locale, &$broken): void {
                if (count($broken) >= 10) {
                    return;
                }
                $once = Polytypo::transform($text, $locale);
                $twice = Polytypo::transform($once, $locale);
                if ($twice !== $once) {
                    $broken[] = "{$locale}: " . json_encode($text) . ' -> ' . json_encode($once) . ' -> ' . json_encode($twice);
                }
            });
        }
        self::assertSame([], $broken);
    }

    #[Test]
    public function isIdempotentAroundTheHtmlLineBoundaryMarker(): void
    {
        $alphabet = [' ', '"', '-', '.', 'a', '1'];
        $broken = [];
        foreach (self::locales() as $locale) {
            $lefts = [];
            self::boundedStrings($alphabet, 2, function (string $s) use (&$lefts): void {
                $lefts[] = $s;
            });
            $rights = [];
            self::boundedStrings($alphabet, 2, function (string $s) use (&$rights): void {
                $rights[] = $s;
            });

            foreach ($lefts as $left) {
                foreach ($rights as $right) {
                    if (count($broken) >= 10) {
                        continue;
                    }
                    $text = "{$left}<!--\n-->{$right}";
                    $once = Polytypo::transform($text, $locale, mode: 'html');
                    $twice = Polytypo::transform($once, $locale, mode: 'html');
                    if ($twice !== $once) {
                        $broken[] = "{$locale}: " . json_encode($text) . ' -> ' . json_encode($once) . ' -> ' . json_encode($twice);
                    }
                }
            }
        }
        self::assertSame([], $broken);
    }
}
