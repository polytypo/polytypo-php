<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Edit;
use Polytypo\Engine\RuleContext;
use Polytypo\Engine\Sentinels;
use Polytypo\Engine\UnicodeUtil;

/**
 * spec/rules/quotes.md (spec 0.5.0), order 40.
 *
 * Mandate 1 (every existing quote glyph is a re-typesetting candidate) and mandate 2 (a space
 * touching a quote mark is sloppiness, not evidence) are this rule's whole architecture. Five
 * passes plus an emit; no backtracking inside a pass, no regular expression, no native-string
 * indexing (ARCHITECTURE.md 4.1, 4.2).
 *
 *   Pass 1 (collectCandidates)  -- collect and classify candidates (quotes.md 3.2)
 *   Pass 2 (pairCandidates)     -- pair the candidates, one stack per width (3.3)
 *   Pass 3 (depthOf)            -- assign glyphs by depth, folded into the render plan (3.4)
 *   Pass 4 (certify)            -- the certification gate (3.5)
 *   Pass 5 (emit)               -- emit edits (3.6)
 *
 * Candidates are `array{index: int, wide: bool, canOpen: bool, canClose: bool}`; pairs are
 * `array{open: int, close: int}` -- plain array shapes rather than value objects, kept internal
 * to this file exactly like Go's unexported qtCandidate/qtPair structs and Ruby's local Structs.
 */
final class QuotesRule
{
    // WIDE -- quotes.md 3.1 WIDE. NARROW is QuoteAmbiguity::isNarrow(), shared with apostrophe so
    // the two rules cannot define two slightly different NARROW sets. WIDE and NARROW are
    // disjoint and their union is QUOTEMARK.
    private const QUOTATION_MARK = 0x22;
    private const LEFT_POINTING_DOUBLE_ANGLE_QUOTATION_MARK = 0xAB;
    private const RIGHT_POINTING_DOUBLE_ANGLE_QUOTATION_MARK = 0xBB;
    private const LEFT_DOUBLE_QUOTATION_MARK = 0x201C;
    private const RIGHT_DOUBLE_QUOTATION_MARK = 0x201D;
    private const DOUBLE_LOW_9_QUOTATION_MARK = 0x201E;
    private const DOUBLE_HIGH_REVERSED_9_QUOTATION_MARK = 0x201F;
    private const REVERSED_DOUBLE_PRIME_QUOTATION_MARK = 0x301D;
    private const DOUBLE_PRIME_QUOTATION_MARK = 0x301E;
    private const LOW_DOUBLE_PRIME_QUOTATION_MARK = 0x301F;

    // BREAK -- quotes.md 3.1 BREAK. Sentinels::LINE_MARKER is a member of BREAK for every rule,
    // everywhere (modes.md 3.2).
    private const LF = 0x0A;
    private const CR = 0x0D;
    private const VT = 0x0B;
    private const FF = 0x0C;
    private const NEL = 0x85;
    private const LS = 0x2028;
    private const PS = 0x2029;

    private const PAREN_OPEN = 0x28;
    private const SQUARE_OPEN = 0x5B;
    private const CURLY_OPEN = 0x7B;

    private const PAREN_CLOSE = 0x29;
    private const SQUARE_CLOSE = 0x5D;
    private const CURLY_CLOSE = 0x7D;
    private const COMMA = 0x2C;
    private const FULL_STOP = 0x2E;
    private const SEMICOLON = 0x3B;
    private const COLON = 0x3A;
    private const EXCLAMATION = 0x21;
    private const QUESTION = 0x3F;
    private const ELLIPSIS = 0x2026;

    private const HYPHEN_MINUS = 0x2D;
    private const NON_BREAKING_HYPHEN = 0x2011;
    private const EN_DASH = 0x2013;
    private const EM_DASH = 0x2014;

    private const DIGIT_ZERO = 0x30;
    private const DIGIT_NINE = 0x39;

    private const STRAIGHT_APOSTROPHE = 0x27;
    private const RIGHT_SINGLE_QUOTATION_MARK = 0x2019;

    private function __construct()
    {
    }

    private static function isWide(int $cp): bool
    {
        return $cp === self::QUOTATION_MARK
            || $cp === self::LEFT_POINTING_DOUBLE_ANGLE_QUOTATION_MARK
            || $cp === self::RIGHT_POINTING_DOUBLE_ANGLE_QUOTATION_MARK
            || $cp === self::LEFT_DOUBLE_QUOTATION_MARK
            || $cp === self::RIGHT_DOUBLE_QUOTATION_MARK
            || $cp === self::DOUBLE_LOW_9_QUOTATION_MARK
            || $cp === self::DOUBLE_HIGH_REVERSED_9_QUOTATION_MARK
            || $cp === self::REVERSED_DOUBLE_PRIME_QUOTATION_MARK
            || $cp === self::DOUBLE_PRIME_QUOTATION_MARK
            || $cp === self::LOW_DOUBLE_PRIME_QUOTATION_MARK;
    }

    private static function isQuoteMark(int $cp): bool
    {
        return self::isWide($cp) || QuoteAmbiguity::isNarrow($cp);
    }

    private static function isDigit(int $cp): bool
    {
        return $cp >= self::DIGIT_ZERO && $cp <= self::DIGIT_NINE;
    }

    private static function isAlnum(int $cp): bool
    {
        return self::isDigit($cp) || UnicodeUtil::isLetter($cp);
    }

    private static function isBreak(int $cp): bool
    {
        return $cp === self::LF || $cp === self::CR || $cp === self::VT || $cp === self::FF
            || $cp === self::NEL || $cp === self::LS || $cp === self::PS
            || $cp === Sentinels::LINE_MARKER;
    }

    /** SPACELIKE = INLINE-SPACE u BREAK. */
    private static function isSpacelike(int $cp): bool
    {
        return QuoteAmbiguity::isInlineSpace($cp) || self::isBreak($cp);
    }

    /**
     * OPENISH. QUOTEMARK is a member of both OPENISH and CLOSEISH, and is exempt from canOpen's
     * closeish rejection -- Lemma A's entire mechanism (quotes.md 5) and the reason a candidate's
     * verdict never depends on which quote glyph its neighbour is. Do not "simplify" this back to
     * per-glyph lists. Sentinels::MARKER is a member too (modes.md 3.3).
     */
    private static function isOpenish(int $cp): bool
    {
        if ($cp === Sentinels::MARKER) {
            return true;
        }

        return $cp === self::PAREN_OPEN || $cp === self::SQUARE_OPEN || $cp === self::CURLY_OPEN
            || self::isQuoteMark($cp);
    }

    /** CLOSEISH -- see isOpenish()'s dual-membership note. */
    private static function isCloseish(int $cp): bool
    {
        if ($cp === Sentinels::MARKER) {
            return true;
        }

        return $cp === self::PAREN_CLOSE || $cp === self::SQUARE_CLOSE || $cp === self::CURLY_CLOSE
            || $cp === self::COMMA || $cp === self::FULL_STOP || $cp === self::SEMICOLON
            || $cp === self::COLON || $cp === self::EXCLAMATION || $cp === self::QUESTION
            || $cp === self::ELLIPSIS || $cp === self::EN_DASH || $cp === self::EM_DASH
            || self::isQuoteMark($cp);
    }

    private static function isDashish(int $cp): bool
    {
        return $cp === self::HYPHEN_MINUS || $cp === self::NON_BREAKING_HYPHEN
            || $cp === self::EN_DASH || $cp === self::EM_DASH;
    }

    /**
     * DELETE-LANDING -- the largest landing class for which every earlier-ordered rule's own
     * classes are unaffected by a quote glyph or a U+0020 (quotes.md 3.7, composition
     * obligation).
     */
    private static function isDeleteLanding(int $cp): bool
    {
        return self::isAlnum($cp) || self::isQuoteMark($cp);
    }

    /**
     * V1ID (quotes.md 3.2, spec 0.4.1) -- a conservative over-approximation, not a claim that
     * every U+0027 becomes U+2019. apostrophe only ever emits U+2019 for a U+0027, but its own
     * case ladder leaves some U+0027s unedited (the prime guard, and "nothing inferable"), and
     * quotes cannot know which without re-deriving apostrophe's verdict against quotes' own
     * not-yet-final output -- circular. V1ID treats every U+0027 as possibly about to become
     * U+2019, and every U+2019 as possibly a U+0027 that already did, so V1's comparison stays
     * invariant across the two rules running in sequence on successive pipeline passes
     * (quotes.md 5, Lemma A).
     */
    private static function v1Identity(int $cp): int
    {
        return $cp === self::STRAIGHT_APOSTROPHE ? self::RIGHT_SINGLE_QUOTATION_MARK : $cp;
    }

    /** Decodes a locale-declared quote glyph (guaranteed by schema to be exactly one code point). */
    private static function glyphCodepoint(string $glyph): int
    {
        return mb_ord($glyph, 'UTF-8');
    }

    /**
     * quotes.md 3.1a -- locale-derived skip sets, computed once per call. These are exactly the
     * positions at which nbsp's N8 can insert a space (nbsp.md 3.10), which is what makes
     * Lemma B's coverage exact rather than a survey.
     *
     * @param array<string, mixed> $quotesData
     * @return array{spaceRight: array<int, true>, spaceLeft: array<int, true>}
     */
    private static function computeSkipSets(array $quotesData): array
    {
        $spaceRight = [];
        $spaceLeft = [];

        foreach ([$quotesData['primary'], $quotesData['secondary']] as $pair) {
            if ($pair['innerSpace'] === 'none') {
                continue;
            }
            $open = self::glyphCodepoint($pair['open']);
            $close = self::glyphCodepoint($pair['close']);
            if ($open === $close) {
                continue;
            }
            $spaceRight[$open] = true;
            $spaceLeft[$close] = true;
        }

        return ['spaceRight' => $spaceRight, 'spaceLeft' => $spaceLeft];
    }

    /**
     * The straight-line walk of quotes.md 3.2: step outward across a maximal INLINE-SPACE run.
     * Sentinels::MARKER and every BREAK stop it, because neither is in INLINE-SPACE.
     */
    private static function skipLeft(array $cp, int $i): int
    {
        $j = $i - 1;
        while ($j >= 0 && QuoteAmbiguity::isInlineSpace($cp[$j])) {
            $j--;
        }

        return $j >= 0 ? $cp[$j] : Sentinels::NONE;
    }

    private static function skipRight(array $cp, int $i): int
    {
        $n = count($cp);
        $j = $i + 1;
        while ($j < $n && QuoteAmbiguity::isInlineSpace($cp[$j])) {
            $j++;
        }

        return $j < $n ? $cp[$j] : Sentinels::NONE;
    }

    /**
     * Pass 1 (quotes.md 3.2) -- collect and classify candidates. canOpen always skips right and
     * canClose always skips left (mandate 2's inner-side skip); the outer side skips only when
     * nbsp can reach it (the locale-derived spaceRight/spaceLeft sets), which is what keeps every
     * verdict inert to nbsp (Lemma B).
     *
     * @param array{spaceRight: array<int, true>, spaceLeft: array<int, true>} $skipSets
     * @param array<int, array{left: string, elided: string, right: string}> $idioms
     * @return array<int, array{index: int, wide: bool, canOpen: bool, canClose: bool}>
     */
    private static function collectCandidates(array $cp, array $skipSets, array $idioms): array
    {
        $n = count($cp);
        $candidates = [];

        // spec 0.5.0: the veto set is the UNION of the cited-idiom match (unchanged since 0.4.0)
        // and the general ambiguous-medial-span shape (quotes.md 3.2a) -- quotes must decline
        // pairing for both, so apostrophe's own case ladder never independently "fixes" a shape
        // quotes left alone.
        $elisionVetoed = QuoteAmbiguity::computeIdiomMatchedIndices($cp, $idioms)
            + QuoteAmbiguity::computeAmbiguousShapeIndices($cp);

        for ($i = 0; $i < $n; $i++) {
            $g = $cp[$i];
            if (!self::isQuoteMark($g)) {
                continue;
            }

            $lLit = QuoteAmbiguity::at($cp, $i - 1);
            $rLit = QuoteAmbiguity::at($cp, $i + 1);
            $lSkip = self::skipLeft($cp, $i);
            $rSkip = self::skipRight($cp, $i);

            $openLeft = isset($skipSets['spaceLeft'][$g]) ? $lSkip : $lLit;
            $closeRight = isset($skipSets['spaceRight'][$g]) ? $rSkip : $rLit;

            $canOpen = ($openLeft === Sentinels::NONE || self::isSpacelike($openLeft) || self::isOpenish($openLeft) || self::isDashish($openLeft))
                && $rSkip !== Sentinels::NONE && !self::isSpacelike($rSkip)
                && (!self::isCloseish($rSkip) || self::isQuoteMark($rSkip) || $rSkip === Sentinels::MARKER);

            $canClose = $lSkip !== Sentinels::NONE && !self::isSpacelike($lSkip)
                && ($closeRight === Sentinels::NONE || self::isSpacelike($closeRight) || self::isCloseish($closeRight) || self::isDashish($closeRight));

            // Medial-elision veto (quotes.md 3.2), NARROW marks only, literal reads: don't,
            // l'ete, O'Brien, 1990's -- and, on a second pipeline pass, don't with U+2019,
            // because apostrophe has converted the mark and U+2019 is also NARROW.
            if (
                QuoteAmbiguity::isNarrow($g) && $lLit !== Sentinels::NONE && $rLit !== Sentinels::NONE
                && self::isAlnum($lLit) && self::isAlnum($rLit)
            ) {
                $canOpen = false;
                $canClose = false;
            }

            // Listed + general ambiguous-shape veto (quotes.md 3.2, spec 0.4.0/0.5.0): both
            // capabilities forced false, overriding every other test in this loop.
            if (isset($elisionVetoed[$i])) {
                $canOpen = false;
                $canClose = false;
            }

            // V1 -- same-V1-identity adjacency veto (quotes.md 3.2), both widths: "", '', <<, "",
            // plus the same shape separated by exactly one INLINE-SPACE code point at a position
            // nbsp can insert or remove (gapInsertable, scoped to Lemma B's two insertion sites).
            $gV1 = self::v1Identity($g);
            $gapInsertable = isset($skipSets['spaceRight'][$g]) || isset($skipSets['spaceLeft'][$g]);
            $leftVetoed = self::v1Identity($lLit) === $gV1
                || ($lLit !== Sentinels::NONE && QuoteAmbiguity::isInlineSpace($lLit) && self::v1Identity($lSkip) === $gV1 && $gapInsertable);
            $rightVetoed = self::v1Identity($rLit) === $gV1
                || ($rLit !== Sentinels::NONE && QuoteAmbiguity::isInlineSpace($rLit) && self::v1Identity($rSkip) === $gV1 && $gapInsertable);
            if ($leftVetoed || $rightVetoed) {
                $canOpen = false;
                $canClose = false;
            }

            if ($canOpen || $canClose) {
                $candidates[] = ['index' => $i, 'wide' => self::isWide($g), 'canOpen' => $canOpen, 'canClose' => $canClose];
            }
        }

        return $candidates;
    }

    /** quotes.md 3.3 vacuous(a, b). Vacuously true when b = a + 1. */
    private static function isVacuous(array $cp, int $a, int $b): bool
    {
        for ($k = $a + 1; $k < $b; $k++) {
            if (!QuoteAmbiguity::isInlineSpace($cp[$k])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pass 2 (quotes.md 3.3) -- pair the candidates, one stack per width. Closing is tried before
     * opening; a candidate reaches exactly one of three outcomes (paired, pushed, unmatched), and
     * a closer that fails the vacuity condition falls through to step 2 and then step 3 rather
     * than being discarded -- the exhaustive three-outcome shape the certification gate depends
     * on.
     *
     * @param array<int, array{index: int, wide: bool, canOpen: bool, canClose: bool}> $candidates
     * @return array<int, array{open: int, close: int}>
     */
    private static function pairCandidates(array $cp, array $candidates): array
    {
        $wideStack = [];
        $narrowStack = [];
        $pairs = [];

        foreach ($candidates as $c) {
            $stackKey = $c['wide'] ? 'wide' : 'narrow';
            $stack = $c['wide'] ? $wideStack : $narrowStack;

            $paired = false;
            if ($c['canClose'] && $stack !== []) {
                $top = $stack[count($stack) - 1];
                if (!self::isVacuous($cp, $top['index'], $c['index'])) {
                    array_pop($stack);
                    $pairs[] = ['open' => $top['index'], 'close' => $c['index']];
                    $paired = true;
                }
            }
            if (!$paired && $c['canOpen']) {
                $stack[] = $c;
            }

            if ($stackKey === 'wide') {
                $wideStack = $stack;
            } else {
                $narrowStack = $stack;
            }
        }

        return $pairs;
    }

    /**
     * Pass 3's depth (quotes.md 3.4), computed over the accepted set A, never the raw pass-2
     * output: on a second run the accepted set is the raw set, so a depth taken over the raw set
     * on run 1 and the accepted set on run 2 would disagree whenever the gate declined anything.
     *
     * @param array<int, array{open: int, close: int}> $pairs
     * @param array{open: int, close: int} $p
     */
    private static function depthOf(array $pairs, array $p): int
    {
        $depth = 1;
        foreach ($pairs as $q) {
            if ($q['open'] < $p['open'] && $p['close'] < $q['close']) {
                $depth++;
            }
        }

        return $depth;
    }

    /** @param array<string, mixed> $quotesData @return array{open: string, close: string, innerSpace: string} */
    private static function pairFor(array $quotesData, int $depth): array
    {
        return $depth % 2 === 1 ? $quotesData['primary'] : $quotesData['secondary'];
    }

    /**
     * quotes.md 3.5 render's glyph/deletion plan, shared by the certification gate's hypothetical
     * and the real emit (pass 5) -- the only difference between them is whether the plan is
     * applied to a throwaway array or actually returned as edits.
     *
     * @param array<int, array{open: int, close: int}> $accepted
     * @param array<string, mixed> $quotesData
     * @return array{replace: array<int, int>, del: array<int, true>}
     */
    private static function computeRenderPlan(array $cp, array $accepted, array $quotesData): array
    {
        $replace = [];
        $del = [];
        $n = count($cp);

        foreach ($accepted as $p) {
            $glyphs = self::pairFor($quotesData, self::depthOf($accepted, $p));
            $replace[$p['open']] = self::glyphCodepoint($glyphs['open']);
            $replace[$p['close']] = self::glyphCodepoint($glyphs['close']);

            if ($glyphs['innerSpace'] !== 'none') {
                continue;
            }

            // Open-side run: the maximal INLINE-SPACE run starting at p.open + 1.
            $openStart = $p['open'] + 1;
            $openEnd = $openStart;
            while ($openEnd < $n && QuoteAmbiguity::isInlineSpace($cp[$openEnd])) {
                $openEnd++;
            }
            $openEmpty = $openEnd === $openStart;
            $openLanding = $openEnd < $n ? $cp[$openEnd] : Sentinels::NONE;

            // Close-side run: the maximal INLINE-SPACE run ending at p.close - 1.
            $closeEnd = $p['close'];
            $closeStart = $closeEnd - 1;
            while ($closeStart >= 0 && QuoteAmbiguity::isInlineSpace($cp[$closeStart])) {
                $closeStart--;
            }
            $closeStart++;
            $closeEmpty = $closeStart === $closeEnd;
            $closeLanding = $closeStart - 1 >= 0 ? $cp[$closeStart - 1] : Sentinels::NONE;

            // A run is deleted iff non-empty, its landing is in DELETE-LANDING, and it is not
            // simultaneously both of the pair's runs -- a pair enclosing nothing but spaces
            // deletes neither (quotes.md 3.5). Unreachable for an accepted pair given pass 2's
            // vacuity condition, but the guard is cheap and the spec states it unconditionally.
            $sameRun = !$openEmpty && !$closeEmpty && $openStart === $closeStart && $openEnd === $closeEnd;

            if (!$openEmpty && self::isDeleteLanding($openLanding) && !$sameRun) {
                for ($k = $openStart; $k < $openEnd; $k++) {
                    $del[$k] = true;
                }
            }
            if (!$closeEmpty && self::isDeleteLanding($closeLanding) && !$sameRun) {
                for ($k = $closeStart; $k < $closeEnd; $k++) {
                    $del[$k] = true;
                }
            }
        }

        return ['replace' => $replace, 'del' => $del];
    }

    /**
     * Applies a render plan, returning the rendered array and the order-preserving index map from
     * surviving input indices to output indices.
     *
     * @param array{replace: array<int, int>, del: array<int, true>} $plan
     * @return array{0: int[], 1: int[]}
     */
    private static function applyRenderPlan(array $cp, array $plan): array
    {
        $y = [];
        $m = array_fill(0, count($cp), -1);

        for ($i = 0; $i < count($cp); $i++) {
            if (isset($plan['del'][$i])) {
                continue;
            }
            $m[$i] = count($y);
            $y[] = $plan['replace'][$i] ?? $cp[$i];
        }

        return [$y, $m];
    }

    /**
     * Pass 4, the certification gate (quotes.md 3.5): the accepted pairing is checked, not
     * proved. Render the hypothetical output, re-run passes 1-2 on it, and decline pairs until
     * the re-run reproduces the accepted set exactly. Declination is simultaneous per round, and
     * when the intersection fails to shrink A, the pair with the greatest open index is forced
     * out -- both clauses are normative, so two ports cannot disagree.
     *
     * @param array<int, array{open: int, close: int}> $initial
     * @param array{spaceRight: array<int, true>, spaceLeft: array<int, true>} $skipSets
     * @param array<int, array{left: string, elided: string, right: string}> $idioms
     * @return array<int, array{open: int, close: int}>
     */
    private static function certify(array $cp, array $initial, array $quotesData, array $skipSets, array $idioms): array
    {
        $accepted = array_values($initial);
        // Each round accepts or strictly shrinks `accepted`; it is finite and the empty set
        // accepts unconditionally, so the loop runs at most |A0| + 1 times (quotes.md 3.5). The
        // bound below is a defensive safety net, not a normative one.
        $maxRounds = count($initial) + 2;

        for ($round = 0; $round <= $maxRounds; $round++) {
            if ($accepted === []) {
                return $accepted;
            }

            $plan = self::computeRenderPlan($cp, $accepted, $quotesData);
            [$y, $m] = self::applyRenderPlan($cp, $plan);
            $rederived = self::pairCandidates($y, self::collectCandidates($y, $skipSets, $idioms));

            $bSet = [];
            foreach ($rederived as $p) {
                $bSet["{$p['open']},{$p['close']}"] = true;
            }

            $projected = [];
            $projSet = [];
            foreach ($accepted as $idx => $p) {
                $proj = ['open' => $m[$p['open']], 'close' => $m[$p['close']]];
                $projected[$idx] = $proj;
                $projSet["{$proj['open']},{$proj['close']}"] = true;
            }

            if (self::pairSetsEqual($projSet, $bSet)) {
                return $accepted;
            }

            $survivors = [];
            foreach ($accepted as $idx => $p) {
                $key = "{$projected[$idx]['open']},{$projected[$idx]['close']}";
                if (isset($bSet[$key])) {
                    $survivors[] = $p;
                }
            }

            if (count($survivors) === count($accepted)) {
                $removeIdx = 0;
                for ($i = 1; $i < count($accepted); $i++) {
                    if ($accepted[$i]['open'] > $accepted[$removeIdx]['open']) {
                        $removeIdx = $i;
                    }
                }
                array_splice($accepted, $removeIdx, 1);
            } else {
                $accepted = $survivors;
            }
        }

        // Unreachable given the termination argument; declines everything rather than looping.
        return [];
    }

    /** @param array<string, true> $a @param array<string, true> $b */
    private static function pairSetsEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $key => $_) {
            if (!isset($b[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pass 5 (quotes.md 3.6). An edit whose replacement equals the span it replaces is never
     * emitted -- the invisible-edit principle, applied per mark, not per pair. Array iteration
     * order is never relied on: both replacement and deletion indices are sorted before edits are
     * built (ARCHITECTURE.md 4.5).
     *
     * @param array<int, array{open: int, close: int}> $accepted
     * @param array<string, mixed> $quotesData
     * @return Edit[]
     */
    private static function emit(array $cp, array $accepted, array $quotesData): array
    {
        $plan = self::computeRenderPlan($cp, $accepted, $quotesData);
        $edits = [];

        $replaceIdx = array_keys($plan['replace']);
        sort($replaceIdx);
        foreach ($replaceIdx as $idx) {
            $newCp = $plan['replace'][$idx];
            if ($cp[$idx] === $newCp) {
                continue;
            }
            $edits[] = new Edit($idx, $idx + 1, [$newCp], 'quotes');
        }

        $delIdx = array_keys($plan['del']);
        sort($delIdx);
        $i = 0;
        while ($i < count($delIdx)) {
            $j = $i;
            while ($j + 1 < count($delIdx) && $delIdx[$j + 1] === $delIdx[$j] + 1) {
                $j++;
            }
            $edits[] = new Edit($delIdx[$i], $delIdx[$j] + 1, [], 'quotes');
            $i = $j + 1;
        }

        usort($edits, static fn (Edit $a, Edit $b): int => $a->start <=> $b->start);

        return $edits;
    }

    /**
     * @param int[] $cp
     * @param array<string, mixed> $localeData
     * @return Edit[]
     */
    public static function scan(array $cp, array $localeData, RuleContext $ctx): array
    {
        $quotesData = $localeData['quotes'];
        $idioms = $quotesData['elisionIdioms'] ?? [];
        $skipSets = self::computeSkipSets($quotesData);

        $candidates = self::collectCandidates($cp, $skipSets, $idioms);
        if ($candidates === []) {
            return [];
        }

        $initialPairs = self::pairCandidates($cp, $candidates);
        if ($initialPairs === []) {
            return [];
        }

        $accepted = self::certify($cp, $initialPairs, $quotesData, $skipSets, $idioms);
        if ($accepted === []) {
            return [];
        }

        return self::emit($cp, $accepted, $quotesData);
    }
}
