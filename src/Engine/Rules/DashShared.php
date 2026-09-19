<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Sentinels;

/**
 * Structural primitives shared by `dashes` (spec/rules/dashes.md, order 30) and `ranges`
 * (spec/rules/ranges.md, order 25). Both rules scan the same DASH-token shape and share the same
 * symmetry/isolation/cluster/joiner guards (dashes.md 3.2, 3.2a, 3.2b) -- this class is the
 * single source of truth for that shared machinery, mirroring the JS reference implementation's
 * dash-shared.ts and the Python/Go/Ruby ports' own equivalents (_dash_shared.py, dash_shared.go,
 * dash_shared.rb). Each rule adds only its own branch-specific guards (P1/P4/P5 for `dashes`;
 * G1-G5 for `ranges`) and its own locale style (`dash.parenthetical` vs `dash.range`) on top of
 * what findTokens() returns.
 *
 * Not a registered rule itself -- an internal helper class for the two rules that are.
 */
final class DashShared
{
    public const HYPHEN_MINUS = 0x2D;
    public const HYPHEN = 0x2010;
    public const FIGURE_DASH = 0x2012;
    public const EN_DASH = 0x2013;
    public const EM_DASH = 0x2014;
    public const HORIZONTAL_BAR = 0x2015;
    public const MINUS_SIGN = 0x2212;
    public const SOFT_HYPHEN = 0x00AD;
    public const NON_BREAKING_HYPHEN = 0x2011;
    public const SMALL_EM_DASH = 0xFE58;
    public const SMALL_HYPHEN_MINUS = 0xFE63;
    public const FULLWIDTH_HYPHEN_MINUS = 0xFF0D;

    public const SPACE = 0x20;
    public const NO_BREAK_SPACE = 0x00A0;
    public const NARROW_NO_BREAK_SPACE = 0x202F;

    public const DIGIT_ZERO = 0x30;
    public const DIGIT_NINE = 0x39;

    private const LF = 0x0A;
    private const CR = 0x0D;
    private const VT = 0x0B;
    private const FF = 0x0C;
    private const NEL = 0x85;
    private const LS = 0x2028;
    private const PS = 0x2029;

    /** dashes.md 3.1 JOINER: U+2060, emitted only around a tight range dash (ranges.md 3.3.1). */
    public const WORD_JOINER = 0x2060;

    public const COMMA = 0x2C;
    public const FULL_STOP = 0x2E;
    private const SEMICOLON = 0x3B;
    private const COLON = 0x3A;
    private const EXCLAMATION = 0x21;
    private const QUESTION = 0x3F;
    private const ELLIPSIS_CHAR = 0x2026;

    private const PAREN_OPEN = 0x28;
    private const PAREN_CLOSE = 0x29;
    private const SQUARE_OPEN = 0x5B;
    private const SQUARE_CLOSE = 0x5D;
    private const CURLY_OPEN = 0x7B;
    private const CURLY_CLOSE = 0x7D;

    private function __construct()
    {
    }

    /** dashes.md 3.1 DASH. */
    public static function isDash(int $cp): bool
    {
        return $cp === self::HYPHEN_MINUS || $cp === self::HYPHEN || $cp === self::EN_DASH ||
            $cp === self::EM_DASH || $cp === self::MINUS_SIGN;
    }

    /** dashes.md 3.1 INERT-DASH: never a candidate, never produced, by either rule. */
    public static function isInertDash(int $cp): bool
    {
        return $cp === self::SOFT_HYPHEN || $cp === self::FIGURE_DASH || $cp === self::NON_BREAKING_HYPHEN ||
            $cp === self::HORIZONTAL_BAR || $cp === self::SMALL_EM_DASH || $cp === self::SMALL_HYPHEN_MINUS ||
            $cp === self::FULLWIDTH_HYPHEN_MINUS;
    }

    /** DASH union INERT-DASH -- the alphabet G2 and T1 read as "a dash". */
    public static function isDashUnion(int $cp): bool
    {
        return self::isDash($cp) || self::isInertDash($cp);
    }

    /** dashes.md 3.1 DIGIT: ASCII only, deliberately -- see ranges.md 7.1. */
    public static function isDigit(int $cp): bool
    {
        return $cp >= self::DIGIT_ZERO && $cp <= self::DIGIT_NINE;
    }

    /**
     * BREAK, including Sentinels::LINE_MARKER: a member of BREAK for every rule everywhere
     * (modes.md 3.2).
     */
    /**
     * ranges.md 3.2a CLOSED-SYMBOL (spec 1.3.0): the symbols conventionally written closed up to
     * a number. A literal code-point set, never a Unicode category test -- a category makes the
     * verdict depend on which Unicode version a runtime was built against, and the five runtimes
     * must agree. The currency part is the U+20A0-U+20CF block by its own bounds, not the subset
     * assigned in some Unicode version: the assigned subset drifts between releases, block
     * bounds do not.
     */
    public static function isClosedUpSymbol(int $cp): bool
    {
        return $cp === 0x0024
            || ($cp >= 0x00A2 && $cp <= 0x00A5)
            || ($cp >= 0x20A0 && $cp <= 0x20CF)
            || $cp === 0x0025
            || $cp === 0x2030
            || $cp === 0x2031
            || $cp === 0x00B0;
    }

    /**
     * ranges.md 3.2 and 3.2a -- is this token a range candidate, and where are its digit runs?
     * null means it is not one, which is the signal that the token belongs to `dashes`.
     *
     * Both sides are decided from the ORIGINAL left/right, simultaneously; a side consumes a
     * closed-up symbol only when the opposite member repeats the same code point. An unmatched
     * symbol leaves the flank a non-DIGIT, so `$15-<euro>20` and `15-$20` are not candidates and
     * do not change hands.
     *
     * @param int[] $cp
     * @return array{left: int, right: int, a: int, b: int, outerLeft: int, outerRight: int}|null
     */
    public static function rangeFlanks(array $cp, int $left, int $right): ?array
    {
        $n = count($cp);
        $innerRight = null;
        if (
            $right >= 0 && $right < $n && self::isClosedUpSymbol($cp[$right])
            && $right + 1 < $n && self::isDigit($cp[$right + 1])
        ) {
            $innerRight = $cp[$right];
        }
        $innerLeft = null;
        if (
            $left > 0 && $left < $n && self::isClosedUpSymbol($cp[$left])
            && self::isDigit($cp[$left - 1])
        ) {
            $innerLeft = $cp[$left];
        }

        $l = $innerLeft === null ? $left : $left - 1;
        $r = $innerRight === null ? $right : $right + 1;
        if ($l < 0 || $l >= $n || $r < 0 || $r >= $n) {
            return null;
        }
        if (!self::isDigit($cp[$l]) || !self::isDigit($cp[$r])) {
            return null;
        }

        $a = $l;
        while ($a > 0 && self::isDigit($cp[$a - 1])) {
            $a--;
        }
        $b = $r;
        while ($b + 1 < $n && self::isDigit($cp[$b + 1])) {
            $b++;
        }

        $outerLeft = -1;
        if ($innerRight !== null) {
            $outerLeft = self::effectiveIndex($cp, $a - 1, -1);
            if ($outerLeft < 0 || $cp[$outerLeft] !== $innerRight) {
                return null;
            }
        }
        $outerRight = -1;
        if ($innerLeft !== null) {
            $outerRight = self::effectiveIndex($cp, $b + 1, 1);
            if ($outerRight < 0 || $cp[$outerRight] !== $innerLeft) {
                return null;
            }
        }

        return ['left' => $l, 'right' => $r, 'a' => $a, 'b' => $b,
            'outerLeft' => $outerLeft, 'outerRight' => $outerRight];
    }

    /**
     * T1's reach is transparent to one CLOSED-SYMBOL on either end of a digit run (spec 1.3.0):
     * the run it protects may be a range member carrying an outer symbol.
     *
     * @param int[] $cp
     */
    public static function skipClosedUpSymbol(array $cp, int $from, int $step): int
    {
        $i = self::effectiveIndex($cp, $from, $step);
        if ($i < 0 || !self::isClosedUpSymbol($cp[$i])) {
            return $from;
        }

        return $i + $step;
    }

    public static function isBreak(int $cp): bool
    {
        return $cp === self::LF || $cp === self::CR || $cp === self::VT || $cp === self::FF ||
            $cp === self::NEL || $cp === self::LS || $cp === self::PS || $cp === Sentinels::LINE_MARKER;
    }

    public static function isNoBreakSpace(int $cp): bool
    {
        return $cp === self::NO_BREAK_SPACE || $cp === self::NARROW_NO_BREAK_SPACE;
    }

    /** Whether a dash.parenthetical/dash.range value is one of the two "-spaced" forms. */
    public static function isSpacedStyle(string $style): bool
    {
        return $style === 'em-spaced' || $style === 'en-spaced';
    }

    /** The target dash glyph for a style value. */
    public static function dashCodePoint(string $style): int
    {
        if ($style === 'em-tight' || $style === 'em-spaced') {
            return self::EM_DASH;
        }

        return self::EN_DASH;
    }

    /**
     * Whether cp[start...end) is exactly $next, code point for code point.
     *
     * @param int[] $cp
     * @param int[] $next
     */
    public static function sameContent(array $cp, int $start, int $end, array $next): bool
    {
        if ($end - $start !== count($next)) {
            return false;
        }
        foreach ($next as $i => $want) {
            if ($cp[$start + $i] !== $want) {
                return false;
            }
        }

        return true;
    }

    /**
     * dashes.md 3.6: every replacement is built from U+0020 alone plus the target dash glyph.
     * $bind is meaningful only for `ranges` (ranges.md 3.3.1) -- `dashes`' parenthetical branch
     * always calls this with bind=false, since a parenthetical dash never binds (an interrupting
     * dash is exactly where a line may break).
     *
     * @return int[]
     */
    public static function buildReplacement(string $style, bool $bind): array
    {
        if (!self::isSpacedStyle($style)) {
            if ($bind) {
                return [self::WORD_JOINER, self::dashCodePoint($style), self::WORD_JOINER];
            }

            return [self::dashCodePoint($style)];
        }

        return [self::SPACE, self::dashCodePoint($style), self::SPACE];
    }

    /**
     * dashes.md 3.2 step 9 (T2)'s own set union CLOSE-BRACKET -- the positions from which
     * `spaces` (order 10) deletes a U+0020, plus U+2026 (T2's set is a strict superset of
     * `spaces`' STRIP-BEFORE by exactly that one code point -- dashes.md 3.2 step 9's own note).
     */
    public static function isStripBeforeOrCloseBracket(int $cp): bool
    {
        return $cp === self::COMMA || $cp === self::FULL_STOP || $cp === self::SEMICOLON ||
            $cp === self::COLON || $cp === self::EXCLAMATION || $cp === self::QUESTION ||
            $cp === self::ELLIPSIS_CHAR ||
            $cp === self::PAREN_CLOSE || $cp === self::SQUARE_CLOSE || $cp === self::CURLY_CLOSE;
    }

    /** T2's OPEN-BRACKET set. */
    public static function isOpenBracket(int $cp): bool
    {
        return $cp === self::PAREN_OPEN || $cp === self::SQUARE_OPEN || $cp === self::CURLY_OPEN;
    }

    /**
     * dashes.md 3.2 step 7's cluster alphabet: DASH union INERT-DASH union DIGIT union JOINER (a
     * joiner `ranges` emitted on an earlier pass must not split a cluster it sits inside -- 3.2b).
     */
    public static function isClusterMember(int $cp): bool
    {
        return self::isDash($cp) || self::isInertDash($cp) || self::isDigit($cp) || $cp === self::WORD_JOINER;
    }

    /**
     * dashes.md 3.2 step 7 -- the cluster guard. A maximal span of cluster members containing
     * this run is inert (declines every token in it) if it holds two or more maximal
     * DASH-union-INERT-DASH runs.
     *
     * @param int[] $cp
     */
    public static function isClusterInert(array $cp, int $s, int $e): bool
    {
        $n = count($cp);
        $start = $s;
        while ($start > 0 && self::isClusterMember($cp[$start - 1])) {
            $start--;
        }
        $end = $e;
        while ($end < $n && self::isClusterMember($cp[$end])) {
            $end++;
        }

        $runs = 0;
        $i = $start;
        while ($i < $end) {
            if (!self::isDashUnion($cp[$i])) {
                $i++;
                continue;
            }
            $runs++;
            if ($runs >= 2) {
                return true;
            }
            while ($i < $end && self::isDashUnion($cp[$i])) {
                $i++;
            }
        }

        return false;
    }

    /**
     * dashes.md 3.2b's "effective neighbour" walk: step from $from in $step direction (+1/-1)
     * across a maximal run of JOINER, returning the resulting index, or -1 if the walk leaves
     * the array.
     *
     * @param int[] $cp
     */
    public static function effectiveIndex(array $cp, int $from, int $step): int
    {
        $n = count($cp);
        $i = $from;
        while ($i >= 0 && $i < $n && $cp[$i] === self::WORD_JOINER) {
            $i += $step;
        }
        if ($i < 0 || $i >= $n) {
            return -1;
        }

        return $i;
    }

    /**
     * effectiveIndex()'s code point, or Sentinels::NONE if the walk leaves the array.
     *
     * @param int[] $cp
     */
    public static function effectiveNeighbour(array $cp, int $from, int $step): int
    {
        $i = self::effectiveIndex($cp, $from, $step);
        if ($i < 0) {
            return Sentinels::NONE;
        }

        return $cp[$i];
    }

    /**
     * dashes.md 3.2 step 8 (T1) -- the spacing-transition guard. A tight token must not become
     * spaced when doing so would insert a U+0020 between itself and a digit run that has another
     * dash on its far side, read through effective neighbours (3.2b). Shared because a `dashes`
     * token becoming spaced can insert a space next to a `ranges` token's digit run, and vice
     * versa.
     *
     * @param int[] $cp
     */
    public static function isSpacingTransitionBlocked(array $cp, int $left, int $right): bool
    {
        $n = count($cp);

        // Spec 1.3.0, position p1: step over a CLOSED-SYMBOL between the token and the run.
        if (
            $left > 0 && $left < $n && self::isClosedUpSymbol($cp[$left])
            && self::isDigit($cp[$left - 1])
        ) {
            $left--;
        }
        if (
            $right >= 0 && $right < $n && self::isClosedUpSymbol($cp[$right])
            && $right + 1 < $n && self::isDigit($cp[$right + 1])
        ) {
            $right++;
        }

        if ($left >= 0 && $left < $n && self::isDigit($cp[$left])) {
            $d = $left;
            while ($d > 0 && self::isDigit($cp[$d - 1])) {
                $d--;
            }
            // Position p2: and over one at the far end of the run.
            $i1 = self::effectiveIndex($cp, self::skipClosedUpSymbol($cp, $d - 1, -1), -1);
            $one = $i1 >= 0 ? $cp[$i1] : Sentinels::NONE;
            $two = $i1 >= 0 ? self::effectiveNeighbour($cp, $i1 - 1, -1) : Sentinels::NONE;
            if (self::isDashUnion($one)) {
                return true;
            }
            if (($one === self::SPACE || self::isNoBreakSpace($one)) && self::isDashUnion($two)) {
                return true;
            }
        }

        if ($right >= 0 && $right < $n && self::isDigit($cp[$right])) {
            $d = $right;
            while ($d + 1 < $n && self::isDigit($cp[$d + 1])) {
                $d++;
            }
            $i1 = self::effectiveIndex($cp, self::skipClosedUpSymbol($cp, $d + 1, 1), 1);
            $one = $i1 >= 0 ? $cp[$i1] : Sentinels::NONE;
            $two = $i1 >= 0 ? self::effectiveNeighbour($cp, $i1 + 1, 1) : Sentinels::NONE;
            if (self::isDashUnion($one)) {
                return true;
            }
            if (($one === self::SPACE || self::isNoBreakSpace($one)) && self::isDashUnion($two)) {
                return true;
            }
        }

        return false;
    }

    /**
     * dashes.md 3.2 steps 1-7 and 3.2a, exactly as they read before the `ranges` split -- the
     * common prefix every DASH-run token must pass before either rule's own branch-specific
     * guards run. Returns every token that survives symmetry, content, joiner-crossing,
     * isolation and cluster guards; each rule then filters to the tokens it owns:
     *
     *   - `ranges` only ever processes a token whose leftCp/rightCp are both DIGIT.
     *   - `dashes` must decline every such token unconditionally (operator decision, spec 0.5.0)
     *     -- never reinterpreting a digit-flanked stroke as a parenthetical dash, regardless of
     *     whether `ranges` is enabled.
     *
     * A token with crossedJoiner=true and non-digit flanks is NOT returned at all (declined
     * inline, exactly as dashes.md 3.2a specifies: "if a joiner was crossed in any other
     * configuration, emit nothing") -- a joiner is `ranges`' own emission alphabet, and an author
     * who types one next to a dash meant it, exactly as with INERT-DASH.
     *
     * Sequencing is load-bearing and must not be reordered: the joiner walk (3.2a) runs
     * immediately after the basic array-bounds check on the token's raw L/R, and only THEN do the
     * BREAK check, the isolation guard (step 6) and the cluster guard (step 7) read the POST-walk
     * left/right -- never the pre-walk ones. Evaluating BREAK/isolation/cluster before the joiner
     * walk is a known bug class (it diverged from the JS reference implementation during an
     * earlier port and had to be fixed): a joiner-adjacent BREAK or space-like character must be
     * read past the joiner, not at it.
     *
     * The token shape mirrors the Go `dashToken` struct / Ruby `DashToken` keyword-init Struct as
     * a plain associative array (no separate value-object class), consistent with how locale data
     * is threaded through this port as a plain array rather than a dedicated type:
     *
     *   - s, e: the DASH run itself, in input-array indices [s, e).
     *   - lsp, rsp: the token's outer spacing (0 or 1 U+0020 on each side).
     *   - left, right: the indices of the code points immediately outside the token's content,
     *     after walking across any adjacent JOINER run (dashes.md 3.2a) -- L-star/R-star in the
     *     spec.
     *   - leftCp, rightCp: cp[left]/cp[right].
     *   - spanStart, spanEnd: the full edit span, including outer spacing and any joiner this
     *     token is re-entering across.
     *   - crossedJoiner: bool.
     *
     * @param int[] $cp
     * @return array<int, array{s: int, e: int, lsp: int, rsp: int, left: int, right: int,
     *     leftCp: int, rightCp: int, spanStart: int, spanEnd: int, crossedJoiner: bool}>
     */
    public static function findTokens(array $cp): array
    {
        $n = count($cp);
        $tokens = [];
        $i = 0;

        while ($i < $n) {
            if (!self::isDash($cp[$i])) {
                $i++;
                continue;
            }

            $s = $i;
            $e = $s;
            while ($e < $n && self::isDash($cp[$e])) {
                $e++;
            }
            $i = $e;

            // dashes.md 3.2 step 2 -- a run longer than three is decoration, not a dash.
            if ($e - $s > 3) {
                continue;
            }

            $lsp = ($s > 0 && $cp[$s - 1] === self::SPACE) ? 1 : 0;
            $rsp = ($e < $n && $cp[$e] === self::SPACE) ? 1 : 0;

            // dashes.md 3.2 step 4 -- symmetry guard.
            if ($lsp !== $rsp) {
                continue;
            }

            // dashes.md 3.2 step 5 -- content on both sides (array-bounds half; BREAK is checked
            // below, after the joiner walk, against the post-walk neighbour).
            $left = $s - 1 - $lsp;
            $right = $e + $rsp;
            if ($left < 0 || $right >= $n) {
                continue;
            }

            // dashes.md 3.2a -- joiner neighbours. This walk, and everything that reads its
            // result, must run before the BREAK/isolation/cluster guards below: those guards
            // read the effective (post-walk) neighbour, not the raw one.
            $joinStart = $left + 1;
            $joinEnd = $right;
            while ($left >= 0 && $cp[$left] === self::WORD_JOINER) {
                $left--;
            }
            while ($right < $n && $cp[$right] === self::WORD_JOINER) {
                $right++;
            }
            if ($left < 0 || $right >= $n) {
                continue;
            }

            $crossedJoiner = ($left + 1 !== $joinStart) || ($right !== $joinEnd);
            $joinStart = $left + 1;
            $joinEnd = $right;

            $leftCp = $cp[$left];
            $rightCp = $cp[$right];

            // dashes.md 3.2a: re-entry across a joiner is only ever a bound range `ranges`
            // produced on an earlier pass. Spec 1.3.0 reads that condition after the
            // closed-up-symbol walk, so a bound range carrying symbols re-enters the same way.
            if ($crossedJoiner && self::rangeFlanks($cp, $left, $right) === null) {
                continue;
            }
            if (self::isBreak($leftCp) || self::isBreak($rightCp)) {
                continue;
            }

            // dashes.md 3.2 step 6 -- isolation guard.
            if (self::isInertDash($leftCp) || self::isInertDash($rightCp)) {
                continue;
            }
            if (self::isDash($leftCp) || self::isDash($rightCp)) {
                continue;
            }
            if ($leftCp === self::SPACE || self::isNoBreakSpace($leftCp)) {
                continue;
            }
            if ($rightCp === self::SPACE || self::isNoBreakSpace($rightCp)) {
                continue;
            }

            // dashes.md 3.2 step 7 -- cluster guard.
            if (self::isClusterInert($cp, $s, $e)) {
                continue;
            }

            $spanStart = min($s - $lsp, $joinStart);
            $spanEnd = max($e + $rsp, $joinEnd);

            $tokens[] = [
                's' => $s,
                'e' => $e,
                'lsp' => $lsp,
                'rsp' => $rsp,
                'left' => $left,
                'right' => $right,
                'leftCp' => $leftCp,
                'rightCp' => $rightCp,
                'spanStart' => $spanStart,
                'spanEnd' => $spanEnd,
                'crossedJoiner' => $crossedJoiner,
            ];
        }

        return $tokens;
    }
}
