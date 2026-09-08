<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Edit;
use Polytypo\Engine\RuleContext;
use Polytypo\Engine\Sentinels;
use Polytypo\Engine\UnicodeUtil;
use Polytypo\PolytypoException;

/**
 * `hyphen` -- spec/rules/hyphen.md (spec 0.1.0), order 35.
 *
 * Replaces U+002D with U+2011 inside a closed list of locale-listed morphological forms
 * (hyphen.prefixes/suffixes/compounds). Explicit index-based scanning over the code-point array
 * only: no regex, no native-string indexing (docs/ARCHITECTURE.md section 4.1, 4.2). Kept
 * `final` and namespaced under Rules so nothing here collides with sibling rule files.
 */
final class HyphenRule
{
    private const HYPHEN_MINUS = 0x2D;
    private const NON_BREAKING_HYPHEN = 0x2011;
    private const DIGIT_ZERO = 0x30;
    private const DIGIT_NINE = 0x39;

    // 3.4: the three lists, in the order that breaks a length tie.
    private const COMPOUND = 0;
    private const PREFIX = 1;
    private const SUFFIX = 2;

    private function __construct()
    {
    }

    /** Out-of-range reads yield NONE, the spec's own boundary value (3.1). */
    private static function at(array $cp, int $i): int
    {
        if ($i < 0 || $i >= count($cp)) {
            return Sentinels::NONE;
        }

        return $cp[$i];
    }

    private static function isDigit(int $cp): bool
    {
        return $cp >= self::DIGIT_ZERO && $cp <= self::DIGIT_NINE;
    }

    /** 3.1 HYPHENISH. U+2010, U+00AD, U+2012, U+2013, U+2014 are deliberately absent. */
    private static function isHyphenish(int $cp): bool
    {
        return $cp === self::HYPHEN_MINUS || $cp === self::NON_BREAKING_HYPHEN;
    }

    /**
     * 3.1 WORDISH = ALNUM u HYPHENISH. U+2011 is a member on purpose: without it a converted
     * hyphen would flip a neighbouring form's boundary verdict between runs (5).
     */
    private static function isWordish(int $cp): bool
    {
        return self::isDigit($cp) || UnicodeUtil::isLetter($cp) || self::isHyphenish($cp);
    }

    /** @return int[] */
    private static function toCodepoints(string $s): array
    {
        $chars = mb_str_split($s, 1, 'UTF-8');

        return array_map(static fn (string $c): int => mb_ord($c, 'UTF-8'), $chars);
    }

    /**
     * Converts one locale word list into match patterns of the given kind. hyphen.md 2 requires
     * every entry to contain at least one U+002D; an entry with none is meaningless and raises
     * POLYTYPO_MALFORMED_LOCALE_DATA directly.
     *
     * @param string[] $entries
     * @param array{cps: int[], kind: int}[] $out
     * @return array{cps: int[], kind: int}[]
     */
    private static function preparePatterns(array $entries, string $field, int $kind, array $out): array
    {
        foreach ($entries as $entry) {
            $cps = self::toCodepoints($entry);
            $hasHyphen = false;
            foreach ($cps as $c) {
                if ($c === self::HYPHEN_MINUS) {
                    $hasHyphen = true;
                    break;
                }
            }
            if (!$hasHyphen) {
                throw new PolytypoException(
                    PolytypoException::CODE_MALFORMED_LOCALE_DATA,
                    "hyphen.{$field} entry \"{$entry}\" contains no U+002D; there is nothing " .
                        'for the rule to convert (spec/rules/hyphen.md section 2).',
                );
            }
            $out[] = ['cps' => $cps, 'kind' => $kind];
        }

        return $out;
    }

    /**
     * 3.3 -- hyphen-lenient, first-character-lenient literal matching. The hyphen leniency
     * covers j = 0 too, because a suffix entry begins with its own hyphen and must keep matching
     * its own output once that hyphen has already been converted to U+2011 (5's idempotency
     * argument depends on this). The first-character case leniency uses the Unicode simple
     * uppercase mapping of the *pattern*, never a locale-dependent case fold of the input
     * (ARCHITECTURE.md section 4.4).
     *
     * @param int[] $cp
     * @param int[] $w
     */
    private static function matchesAt(array $cp, int $a, array $w): bool
    {
        if ($a + count($w) > count($cp)) {
            return false;
        }

        foreach ($w as $j => $p) {
            $c = $cp[$a + $j];
            if ($p === self::HYPHEN_MINUS) {
                if (!self::isHyphenish($c)) {
                    return false;
                }
                continue;
            }
            if ($c === $p) {
                continue;
            }
            if ($j === 0 && UnicodeUtil::isLetter($p) && $c === UnicodeUtil::simpleUppercase($p)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * 3.4 -- the longest entry that matches at `a`, ties broken compounds, prefixes, suffixes.
     * `patterns` is built compounds-first, prefixes-second, suffixes-third, so keeping the
     * first-seen entry on a length tie already yields that order.
     *
     * @param int[] $cp
     * @param array{cps: int[], kind: int}[] $patterns
     * @return array{cps: int[], kind: int}|null
     */
    private static function select(array $cp, int $a, array $patterns): ?array
    {
        $best = null;
        foreach ($patterns as $pattern) {
            if (!self::matchesAt($cp, $a, $pattern['cps'])) {
                continue;
            }
            if ($best === null || count($pattern['cps']) > count($best['cps'])) {
                $best = $pattern;
            }
        }

        return $best;
    }

    /** 3.4 C -- a compound must be a whole word. */
    private static function guardCompound(array $cp, int $a, int $k): bool
    {
        $before = self::at($cp, $a - 1);
        if ($before !== Sentinels::NONE && self::isWordish($before)) {
            return false;
        }

        $after = self::at($cp, $a + $k);

        return $after === Sentinels::NONE || !self::isWordish($after);
    }

    /** 3.4 P -- a prefix starts a word and must actually prefix something. */
    private static function guardPrefix(array $cp, int $a, int $k): bool
    {
        $before = self::at($cp, $a - 1);
        if ($before !== Sentinels::NONE && self::isWordish($before)) {
            return false;
        }

        return UnicodeUtil::isLetter(self::at($cp, $a + $k));
    }

    /** 3.4 S -- a suffix must actually suffix something and must end the word. */
    private static function guardSuffix(array $cp, int $a, int $k): bool
    {
        if (!UnicodeUtil::isLetter(self::at($cp, $a - 1))) {
            return false;
        }

        $after = self::at($cp, $a + $k);

        return $after === Sentinels::NONE || !self::isWordish($after);
    }

    private static function bind(int $index): Edit
    {
        return new Edit($index, $index + 1, [self::NON_BREAKING_HYPHEN], 'hyphen');
    }

    /** @param int[] $cp @param array<string, mixed> $localeData @return Edit[] */
    public static function scan(array $cp, array $localeData, RuleContext $ctx): array
    {
        $hyphen = $localeData['hyphen'];
        $prefixes = $hyphen['prefixes'];
        $suffixes = $hyphen['suffixes'];
        $compounds = $hyphen['compounds'];

        // 2: with all three lists empty the rule emits nothing for any input -- the common case
        // for every v1 locale except ru.
        if (count($prefixes) === 0 && count($suffixes) === 0 && count($compounds) === 0) {
            return [];
        }

        $patterns = [];
        $patterns = self::preparePatterns($compounds, 'compounds', self::COMPOUND, $patterns);
        $patterns = self::preparePatterns($prefixes, 'prefixes', self::PREFIX, $patterns);
        $patterns = self::preparePatterns($suffixes, 'suffixes', self::SUFFIX, $patterns);

        $n = count($cp);
        $edits = [];
        $a = 0;
        while ($a < $n) {
            $selected = self::select($cp, $a, $patterns);
            if ($selected === null) {
                $a++;
                continue;
            }

            // 3.4: matching and guarding are separate steps, and there is no backtracking. A
            // guard failure ends the position; no shorter entry is tried at `a`.
            $w = $selected['cps'];
            $k = count($w);
            switch ($selected['kind']) {
                case self::COMPOUND:
                    if (!self::guardCompound($cp, $a, $k)) {
                        $a++;
                        continue 2;
                    }
                    foreach ($w as $j => $p) {
                        if ($p === self::HYPHEN_MINUS && $cp[$a + $j] !== self::NON_BREAKING_HYPHEN) {
                            $edits[] = self::bind($a + $j);
                        }
                    }
                    break;
                case self::PREFIX:
                    if (!self::guardPrefix($cp, $a, $k)) {
                        $a++;
                        continue 2;
                    }
                    // The entry's own last code point is its hyphen (hyphen.md 2).
                    if ($w[$k - 1] === self::HYPHEN_MINUS && $cp[$a + $k - 1] !== self::NON_BREAKING_HYPHEN) {
                        $edits[] = self::bind($a + $k - 1);
                    }
                    break;
                default: // SUFFIX
                    if (!self::guardSuffix($cp, $a, $k)) {
                        $a++;
                        continue 2;
                    }
                    // A suffix is matched at its own hyphen, which is its first code point.
                    if ($w[0] === self::HYPHEN_MINUS && $cp[$a] !== self::NON_BREAKING_HYPHEN) {
                        $edits[] = self::bind($a);
                    }
                    break;
            }
            $a += $k;
        }

        return $edits;
    }
}
