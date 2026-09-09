<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Codepoints;
use Polytypo\Engine\Sentinels;
use Polytypo\Engine\UnicodeUtil;

/**
 * Shared ambiguous-medial-span predicate -- spec/rules/quotes.md 3.2 ("Listed elision veto" and
 * "General ambiguous-medial-span veto") and spec/rules/apostrophe.md 3.4. One definition, used
 * identically by QuotesRule and ApostropheRule, so the two rules cannot drift apart on what
 * counts as ambiguous (mirrors ref-js's quote-ambiguity.ts, ref-python's _quote_ambiguity.py, the
 * Go port's quote_ambiguity.go and the Ruby port's quote_ambiguity.rb).
 *
 * The shape (quotes.md 3.2, "General ambiguous-medial-span veto"): a pair of straight ASCII
 * single quotes (U+0027) enclosing 1-3 LETTER code points, with at least one INLINE-SPACE code
 * point immediately outside each mark -- `rock 'n' roll`, `She chose 'A' today`. Only the single
 * adjacent code point is tested on each side; a longer run of inline spaces further out does not
 * invalidate the match (quotes.md 3.2's "at least one, deliberately not exactly one").
 *
 * Without a matching quotes.elisionIdioms entry, neither quotes nor apostrophe may touch either
 * mark: quotes must not pair them as an ordinary quotation, and apostrophe's own case ladder
 * (which would otherwise independently read the left mark as a leading elision and the right one
 * as a trailing possessive/elision, apostrophe.md 3.3 cases 3/4) must not convert them either.
 *
 * Not a registered rule -- a helper the two real rules (order 40 and 50) call into.
 */
final class QuoteAmbiguity
{
    // NARROW -- quotes.md 3.1 NARROW -- every glyph an elision idiom's marks may appear as across
    // pipeline passes (straight, or already curled by an earlier pass).
    private const STRAIGHT_APOSTROPHE = 0x27;
    private const LEFT_SINGLE_QUOTATION_MARK = 0x2018;
    private const RIGHT_SINGLE_QUOTATION_MARK = 0x2019;
    private const SINGLE_LOW_9_QUOTATION_MARK = 0x201A;
    private const SINGLE_HIGH_REVERSED_9_QUOTATION_MARK = 0x201B;
    private const SINGLE_LEFT_POINTING_ANGLE_QUOTATION_MARK = 0x2039;
    private const SINGLE_RIGHT_POINTING_ANGLE_QUOTATION_MARK = 0x203A;

    // INLINE-SPACE -- quotes.md 3.1 INLINE-SPACE, deliberately excluding BREAK/MARKER/
    // LINE_MARKER so this shape never crosses a line or span boundary (modes.md 3.3) -- the same
    // anchor the elisionIdioms matcher already uses.
    private const SPACE = 0x20;
    private const TAB = 0x09;
    private const NO_BREAK_SPACE = 0xA0;
    private const NARROW_NO_BREAK_SPACE = 0x202F;
    private const FIGURE_SPACE = 0x2007;
    private const THIN_SPACE = 0x2009;
    private const HAIR_SPACE = 0x200A;

    // quotes.md 3.2 -- the one code point the universal medial-n veto's span may enclose, in
    // either case.
    private const LOWER_N = 0x6E;
    private const UPPER_N = 0x4E;

    private function __construct()
    {
    }

    /** cp[i], or Sentinels::NONE if i is out of bounds -- the spec's own boundary value. */
    public static function at(array $cp, int $i): int
    {
        if ($i < 0 || $i >= count($cp)) {
            return Sentinels::NONE;
        }

        return $cp[$i];
    }

    /** NARROW membership, shared with QuotesRule so the two rules cannot define two sets. */
    public static function isNarrow(int $cp): bool
    {
        return $cp === self::STRAIGHT_APOSTROPHE
            || $cp === self::LEFT_SINGLE_QUOTATION_MARK
            || $cp === self::RIGHT_SINGLE_QUOTATION_MARK
            || $cp === self::SINGLE_LOW_9_QUOTATION_MARK
            || $cp === self::SINGLE_HIGH_REVERSED_9_QUOTATION_MARK
            || $cp === self::SINGLE_LEFT_POINTING_ANGLE_QUOTATION_MARK
            || $cp === self::SINGLE_RIGHT_POINTING_ANGLE_QUOTATION_MARK;
    }

    /** INLINE-SPACE membership, shared with QuotesRule. */
    public static function isInlineSpace(int $cp): bool
    {
        return $cp === self::SPACE
            || $cp === self::TAB
            || $cp === self::NO_BREAK_SPACE
            || $cp === self::NARROW_NO_BREAK_SPACE
            || $cp === self::FIGURE_SPACE
            || $cp === self::THIN_SPACE
            || $cp === self::HAIR_SPACE;
    }

    private static function isAlnum(int $cp): bool
    {
        return ($cp >= 0x30 && $cp <= 0x39) || UnicodeUtil::isLetter($cp);
    }

    /**
     * Folds ASCII A-Z to a-z, ASCII-only -- the same convention nbsp's afterShortWords and the
     * elisionIdioms matcher already use (ARCHITECTURE.md 4.4: never a platform locale case-fold).
     */
    private static function asciiLower(int $cp): int
    {
        if ($cp >= 0x41 && $cp <= 0x5A) {
            return $cp + 0x20;
        }

        return $cp;
    }

    /**
     * Compares cp[start .. start+count(elided)) against elided exactly, code point for code
     * point -- no case leniency, ever, on the elided content (quotes.md 3.2). Every comparison
     * goes through Codepoints::isValidCodepoint() first: a MARKER/LINE_MARKER/NONE sentinel (a
     * negative int, never a valid code point) must never be treated as if it could equal a real
     * code point. This is the guard the equivalent Python port needed after a real crash
     * (`ValueError: chr() arg not in range`) when a span straddling a span-boundary marker was
     * assembled into a string and compared to a literal -- this method never assembles the
     * candidate span into a PHP string, comparison stays code point vs. code point throughout,
     * but the explicit validity check is kept so a marker still correctly fails the match rather
     * than accidentally comparing equal (quotes.md 3.2 / apostrophe.md 3.4, rows H1-H3: MARKER is
     * not INLINE-SPACE, so a context split by an element boundary must not match).
     *
     * @param int[] $elided
     */
    private static function elidedMatches(array $cp, int $start, array $elided): bool
    {
        foreach ($elided as $w => $want) {
            $c = self::at($cp, $start + $w);
            if (!Codepoints::isValidCodepoint($c)) {
                return false;
            }
            if ($c !== $want) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reports whether the count($word) code points immediately before index `end` (exclusive)
     * match word exactly -- except the first code point, compared ASCII-case-insensitive -- and
     * have a legal outer (left) word boundary: NONE, or not LETTER/DIGIT (quotes.md 3.2's Word
     * definition). The caller has already verified the code point at `end` itself is a legal
     * right-hand boundary (a single INLINE-SPACE code point). Guarded against a marker exactly
     * like elidedMatches() above.
     *
     * @param int[] $word
     */
    private static function wordEndsAt(array $cp, int $end, array $word): bool
    {
        $start = $end - count($word);
        if ($start < 0) {
            return false;
        }

        foreach ($word as $k => $want) {
            $c = self::at($cp, $start + $k);
            if (!Codepoints::isValidCodepoint($c)) {
                return false;
            }
            if ($k === 0) {
                if (self::asciiLower($c) !== self::asciiLower($want)) {
                    return false;
                }
            } elseif ($c !== $want) {
                return false;
            }
        }

        $before = self::at($cp, $start - 1);

        return $before === Sentinels::NONE || !self::isAlnum($before);
    }

    /**
     * wordEndsAt()'s mirror image: word must start exactly at `start`, first code point
     * ASCII-case-insensitive, with a legal outer (right) word boundary immediately after it.
     *
     * @param int[] $word
     */
    private static function wordStartsAt(array $cp, int $start, array $word): bool
    {
        $n = count($cp);

        foreach ($word as $k => $want) {
            $c = self::at($cp, $start + $k);
            if (!Codepoints::isValidCodepoint($c)) {
                return false;
            }
            if ($k === 0) {
                if (self::asciiLower($c) !== self::asciiLower($want)) {
                    return false;
                }
            } elseif ($c !== $want) {
                return false;
            }
        }

        $after = $start + count($word);
        if ($after >= $n) {
            return true;
        }

        return !self::isAlnum($cp[$after]);
    }

    /**
     * The listed elision veto (quotes.md 3.2, spec 0.4.0), locale data quotes.elisionIdioms.
     * Bounded literal scan for `left, NARROW, elided, NARROW, right` (`rock 'n' roll`'s
     * {left: "rock", elided: "n", right: "roll"}). Both marks of a match are returned, as keys of
     * the result (used as a set). Matches on NARROW quote marks generally (U+0027 and
     * already-curly U+2018/U+2019), not only straight ASCII -- required for quotes' own
     * idempotency (an idiom must still veto pairing on a second pipeline pass, after apostrophe
     * has curled the marks).
     *
     * @param array<int, array{left: string, elided: string, right: string}> $idioms
     * @return array<int, true>
     */
    public static function computeIdiomMatchedIndices(array $cp, array $idioms): array
    {
        $vetoed = [];
        if ($idioms === []) {
            return $vetoed;
        }

        $n = count($cp);
        $compiled = [];
        foreach ($idioms as $idiom) {
            $compiled[] = [
                'left' => Codepoints::toCodepoints($idiom['left']),
                'elided' => Codepoints::toCodepoints($idiom['elided']),
                'right' => Codepoints::toCodepoints($idiom['right']),
            ];
        }

        for ($i = 0; $i < $n; $i++) {
            $g = $cp[$i];
            if (!self::isNarrow($g)) {
                continue;
            }

            $lLit = self::at($cp, $i - 1);
            if ($lLit === Sentinels::NONE || !self::isInlineSpace($lLit)) {
                continue;
            }

            foreach ($compiled as $idiom) {
                $k = count($idiom['elided']);
                $j = $i + 1 + $k;
                if ($j >= $n) {
                    continue;
                }
                if (!self::elidedMatches($cp, $i + 1, $idiom['elided'])) {
                    continue;
                }
                if (!self::isNarrow($cp[$j])) {
                    continue;
                }

                $rLit = self::at($cp, $j + 1);
                if ($rLit === Sentinels::NONE || !self::isInlineSpace($rLit)) {
                    continue;
                }

                if (!self::wordEndsAt($cp, $i - 1, $idiom['left'])) {
                    continue;
                }
                if (!self::wordStartsAt($cp, $j + 2, $idiom['right'])) {
                    continue;
                }

                $vetoed[$i] = true;
                $vetoed[$j] = true;
            }
        }

        return $vetoed;
    }

    /**
     * The universal medial-n elision shape, locale-independent (quotes.md 3.2, spec 1.1.0): a
     * pair of NARROW marks enclosing exactly one code point, U+006E or U+004E, with at least one
     * INLINE-SPACE code point immediately outside each mark. Both mark positions are returned for
     * every match. A superset of computeIdiomMatchedIndices()'s output for every idiom whose
     * elided field is a single n (true of every idiom shipped so far), but computed independently
     * rather than assumed, since a future idiom's elided field is not required to be that short.
     *
     * NARROW rather than U+0027 alone is an IDEMPOTENCY obligation, not a preference: this veto's
     * marks are converted to U+2019 by `apostrophe`, so a straight-ASCII-only predicate would not
     * recognise its own output and pass 2 would pair `rock 'n' roll`'s converted form as an
     * ordinary NARROW quotation on the next pipeline run.
     *
     * @return array<int, true>
     */
    public static function computeAmbiguousShapeIndices(array $cp): array
    {
        $ambiguous = [];
        $n = count($cp);

        for ($i = 0; $i < $n; $i++) {
            if (!self::isNarrow(self::at($cp, $i))) {
                continue;
            }

            $lLit = self::at($cp, $i - 1);
            if ($lLit === Sentinels::NONE || !self::isInlineSpace($lLit)) {
                continue;
            }

            $enclosed = self::at($cp, $i + 1);
            if ($enclosed !== self::LOWER_N && $enclosed !== self::UPPER_N) {
                continue;
            }

            $j = $i + 2;
            if (!self::isNarrow(self::at($cp, $j))) {
                continue;
            }

            $rLit = self::at($cp, $j + 1);
            if ($rLit === Sentinels::NONE || !self::isInlineSpace($rLit)) {
                continue;
            }

            $ambiguous[$i] = true;
            $ambiguous[$j] = true;
        }

        return $ambiguous;
    }
}
