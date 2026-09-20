<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Edit;
use Polytypo\Engine\RuleContext;
use Polytypo\Engine\Sentinels;
use Polytypo\Engine\UnicodeUtil;
use Polytypo\PolytypoException;

/**
 * `nbsp` -- spec/rules/nbsp.md (spec 1.2.0), order 70 (last).
 *
 * Ten sub-rules, N1 through N10, evaluated in that fixed order (3.2); each produces candidate
 * edits keyed by the index of the space (or insertion point) it claims, and the first sub-rule to
 * claim an index wins. The claims table is a positional array indexed 0..count(cp), never keyed
 * associatively by anything but that index -- a map-iteration implementation would resolve
 * conflicts differently in Go (ARCHITECTURE.md section 4.5). No regex, no native-string indexing
 * (ARCHITECTURE.md section 4.1, 4.2).
 */
final class NbspRule
{
    private const SPACE = 0x20;
    private const TAB = 0x09;
    private const NBSP = 0xA0;
    private const NNBSP = 0x202F;
    private const FULL_STOP = 0x2E;

    private const DIGIT_ZERO = 0x30;
    private const DIGIT_NINE = 0x39;

    private const PAREN_OPEN = 0x28;
    private const SQUARE_OPEN = 0x5B;
    private const BRACE_OPEN = 0x7B;
    private const PAREN_CLOSE = 0x29;
    private const SQUARE_CLOSE = 0x5D;
    private const BRACE_CLOSE = 0x7D;

    private const SEMICOLON = 0x3B;
    private const AMPERSAND = 0x26;
    private const HASH = 0x23;
    /** The longest HTML named reference is 31 code points ("CounterClockwiseContourIntegral"). */
    private const MAX_CHARACTER_REFERENCE_NAME = 32;

    private const EN_DASH = 0x2013;
    private const EM_DASH = 0x2014;
    private const ELLIPSIS = 0x2026;

    private function __construct()
    {
    }

    /**
     * nbsp.md 3.3 step 4: do the code points left of this ";" have the shape of a character
     * reference? A bounded left walk over ASCII alphanumerics, optionally one "#", then "&".
     * Shape, not the HTML named-reference table -- declining on "&notaname;" costs nothing, and
     * no runtime carries thousands of entries for it.
     *
     * @param int[] $cp
     */
    private static function endsCharacterReference(array $cp, int $i): bool
    {
        $j = $i - 1;
        while ($j >= 0 && self::isAsciiAlphanumeric($cp[$j])) {
            $j--;
        }
        $length = $i - 1 - $j;
        if ($length < 1 || $length > self::MAX_CHARACTER_REFERENCE_NAME) {
            return false;
        }
        if ($j >= 0 && $cp[$j] === self::HASH) {
            $j--;
        }

        return $j >= 0 && $cp[$j] === self::AMPERSAND;
    }

    private static function isAsciiAlphanumeric(int $c): bool
    {
        return ($c >= 0x30 && $c <= 0x39) || ($c >= 0x41 && $c <= 0x5A) || ($c >= 0x61 && $c <= 0x7A);
    }

    /** cp[i], or Sentinels::NONE if i is out of bounds -- the spec's own boundary value. */
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

    private static function isAlnum(int $cp): bool
    {
        return $cp !== Sentinels::NONE && (self::isDigit($cp) || UnicodeUtil::isLetter($cp));
    }

    /**
     * BREAK (nbsp.md 3.1), including LINE_MARKER -- a member of BREAK for every rule, everywhere
     * (modes.md 3.2).
     *
     * Sentinels::MARKER is not a member here; see isOpenish()/isCloseish() for its membership.
     */
    private static function isBreak(int $cp): bool
    {
        return $cp === 0x0A || $cp === 0x0D || $cp === 0x0B || $cp === 0x0C || $cp === 0x85 ||
            $cp === 0x2028 || $cp === 0x2029 || $cp === Sentinels::LINE_MARKER;
    }

    private static function isNoBreak(int $cp): bool
    {
        return $cp === self::NBSP || $cp === self::NNBSP;
    }

    /**
     * OTHER-SPACE (nbsp.md 3.1): the fixed-width spaces. A member of SPACELIKE for boundary
     * purposes, but never converted and never an "already correct" state -- a thin or figure
     * space the author placed stays exactly where it is.
     */
    private static function isOtherSpace(int $cp): bool
    {
        return ($cp >= 0x2000 && $cp <= 0x200A) || $cp === 0x205F || $cp === 0x3000;
    }

    /**
     * SPACELIKE, with NOBREAK included. Every boundary test in this rule uses this predicate and
     * never U+0020 alone; that single decision is what makes the rule idempotent (nbsp.md 3.1).
     */
    private static function isSpaceLike(int $cp): bool
    {
        return $cp === self::SPACE || self::isNoBreak($cp) || $cp === self::TAB ||
            self::isOtherSpace($cp) || self::isBreak($cp);
    }

    /**
     * SENTENCE-DASH (nbsp.md 3.1): U+2013 and U+2014 only, never a hyphen. A hyphen marks an
     * intra-word position by construction, so the token after it is not a free-standing word --
     * without this exclusion "из-за дождя" would bind twice over, once by `hyphen` producing
     * "из‑за" and once by N3 reading the compound's tail "за" as a listed preposition (nbsp.md
     * 3.5 step 2). An em or en dash does open a phrase, so "-- в Москве" still binds.
     */
    private static function isSentenceDash(int $cp): bool
    {
        return $cp === self::EN_DASH || $cp === self::EM_DASH;
    }

    private static function malformed(string $message): never
    {
        throw new PolytypoException(PolytypoException::CODE_MALFORMED_LOCALE_DATA, $message);
    }

    private static function singleCodePointValue(string $entry, string $field): int
    {
        $cps = mb_str_split($entry);
        if (count($cps) !== 1) {
            self::malformed(
                sprintf('nbsp.%s entry %s is not exactly one code point.', $field, var_export($entry, true)),
            );
        }

        return mb_ord($cps[0]);
    }

    /**
     * Longest first, so each sub-rule's "longest match wins at a given a" (nbsp.md 3.5) is a
     * linear search that returns on the first match.
     *
     * @param string[] $entries
     * @return array<int, int[]>
     */
    private static function prepareList(array $entries): array
    {
        $lists = array_map(
            static fn (string $entry): array => array_map(mb_ord(...), mb_str_split($entry)),
            $entries,
        );
        usort($lists, static fn (array $a, array $b): int => count($b) <=> count($a));

        return $lists;
    }

    /**
     * Resolves the locale's nbsp and quotes fields to code points once. nbsp.md 2 lists the
     * fields; 2.1 explains why the mechanism (U+00A0 vs U+202F, convert-only vs insert) lives
     * here and not in the locale file.
     *
     * @param array<string, mixed> $localeData
     * @return array<string, mixed>
     */
    private static function prepare(array $localeData, int $narrowTarget = 0x202F): array
    {
        $data = $localeData['nbsp'];

        $beforePunctuation = array_map(
            static fn (string $e): int => self::singleCodePointValue($e, 'beforePunctuation'),
            $data['beforePunctuation'],
        );
        $narrowBeforePunctuation = array_map(
            static fn (string $e): int => self::singleCodePointValue($e, 'narrowBeforePunctuation'),
            $data['narrowBeforePunctuation'],
        );

        // nbsp.md 2 precondition: the two arrays must be disjoint. locale.schema.json does not
        // enforce this; an implementation that finds a code point in both must raise
        // POLYTYPO_MALFORMED_LOCALE_DATA rather than pick a winner silently.
        foreach ($beforePunctuation as $cp) {
            if (in_array($cp, $narrowBeforePunctuation, true)) {
                self::malformed(sprintf(
                    'nbsp.beforePunctuation and nbsp.narrowBeforePunctuation both list U+%04X; ' .
                    'they must be disjoint (spec/rules/nbsp.md section 2).',
                    $cp,
                ));
            }
        }

        $quotes = $localeData['quotes'];
        $primary = $quotes['primary'];
        $secondary = $quotes['secondary'];
        $opens = [
            self::PAREN_OPEN, self::SQUARE_OPEN, self::BRACE_OPEN,
            self::singleCodePointValue($primary['open'], 'quotes.primary.open'),
            self::singleCodePointValue($secondary['open'], 'quotes.secondary.open'),
        ];
        $closes = [
            self::PAREN_CLOSE, self::SQUARE_CLOSE, self::BRACE_CLOSE,
            self::singleCodePointValue($primary['close'], 'quotes.primary.close'),
            self::singleCodePointValue($secondary['close'], 'quotes.secondary.close'),
        ];

        $quotePairs = [];
        foreach ([$primary, $secondary] as $pair) {
            if ($pair['innerSpace'] === 'none') {
                continue;
            }

            $openCp = self::singleCodePointValue($pair['open'], 'quotes.open');
            $closeCp = self::singleCodePointValue($pair['close'], 'quotes.close');
            // 3.10 sidedness precondition: an open glyph equal to its close glyph cannot be told
            // apart without the pairing information only `quotes` has. Documented no-op (7.5).
            if ($openCp === $closeCp) {
                continue;
            }

            $target = $pair['innerSpace'] === 'nbsp' ? self::NBSP : $narrowTarget;
            $quotePairs[] = ['open' => $openCp, 'close' => $closeCp, 'target' => $target];
        }

        return [
            'beforePunctuation' => $beforePunctuation,
            'narrowBeforePunctuation' => $narrowBeforePunctuation,
            'shortWords' => self::prepareList($data['afterShortWords']),
            'abbreviations' => self::prepareList($data['abbreviations']),
            'units' => self::prepareList($data['beforeUnits']),
            'beforeNumber' => self::prepareList($data['beforeNumber']),
            'beforeWord' => self::prepareList($data['beforeWord']),
            'symbols' => self::prepareList($data['afterSymbols']),
            'initialBinding' => $data['initialBinding'],
            'opens' => $opens,
            'closes' => $closes,
            'quotePairs' => $quotePairs,
        ];
    }

    /**
     * OPENISH / CLOSEISH (nbsp.md 3.1): the ASCII brackets plus every locale quote glyph.
     *
     * Spec 1.2.0 splits Sentinels::MARKER's membership (nbsp.md 7 item 12, modes.md 3.3): it is in
     * CLOSEISH, read only by N1/N2's right-context guard, so "<strong>gel :</strong>" in `fr` gets
     * its no-break space back after `spaces` deletes the typed one; it is NOT in OPENISH, whose
     * quote-glyph guard would otherwise lose the narrow space in "<em>non</em> !". LINE_MARKER is
     * in neither -- it is a member of BREAK only.
     *
     * @param array<string, mixed> $prep
     */
    private static function isOpenish(array $prep, int $cp): bool
    {
        return in_array($cp, $prep['opens'], true);
    }

    /** @param array<string, mixed> $prep */
    private static function isCloseish(array $prep, int $cp): bool
    {
        return $cp === Sentinels::MARKER || in_array($cp, $prep['closes'], true);
    }

    /** @param array<string, mixed> $prep */
    private static function isMark(array $prep, int $cp): bool
    {
        return in_array($cp, $prep['beforePunctuation'], true) || in_array($cp, $prep['narrowBeforePunctuation'], true);
    }

    /**
     * The only writers into the claims table. Both no-op if the index is already claimed, which
     * is what makes sub-rule evaluation order equal first-claim-wins (nbsp.md 3.2).
     *
     * @param array<int, Edit|null> $claims
     */
    private static function claimConversion(array &$claims, int $index, int $target): void
    {
        if ($claims[$index] !== null) {
            return;
        }
        $claims[$index] = new Edit($index, $index + 1, [$target], 'nbsp');
    }

    /** @param array<int, Edit|null> $claims */
    private static function claimInsertion(array &$claims, int $index, int $target): void
    {
        if ($claims[$index] !== null) {
            return;
        }
        $claims[$index] = new Edit($index, $index, [$target], 'nbsp');
    }

    /** @param int[] $w */
    private static function matchExact(array $cp, int $a, array $w): bool
    {
        if ($a + count($w) > count($cp)) {
            return false;
        }
        foreach ($w as $j => $want) {
            if ($cp[$a + $j] !== $want) {
                return false;
            }
        }

        return true;
    }

    /**
     * nbsp.md 3.5 step 1: exact except that the pattern's first code point may also match its
     * Unicode simple uppercase mapping -- a plain code-point-to-code-point table, never a
     * locale-sensitive case operation (ARCHITECTURE.md section 4.4).
     *
     * @param int[] $w
     */
    private static function matchFirstCharLenient(array $cp, int $a, array $w): bool
    {
        if ($w === [] || $a + count($w) > count($cp)) {
            return false;
        }

        $head = $cp[$a];
        $first = $w[0];
        if ($head !== $first && $head !== UnicodeUtil::simpleUppercase($first)) {
            return false;
        }

        for ($j = 1; $j < count($w); $j++) {
            if ($cp[$a + $j] !== $w[$j]) {
                return false;
            }
        }

        return true;
    }

    /**
     * nbsp.md 3.6 step 1: exact except that a pattern U+0020 also matches an existing U+00A0 or
     * U+202F in the input, so a previously-converted abbreviation still matches on a later run
     * (the idempotency property nbsp.md 5 item 2 requires of N4).
     *
     * @param int[] $w
     */
    private static function matchSpaceLenient(array $cp, int $a, array $w): bool
    {
        if ($a + count($w) > count($cp)) {
            return false;
        }

        foreach ($w as $j => $want) {
            $got = $cp[$a + $j];
            if ($got === $want) {
                continue;
            }
            if ($want === self::SPACE && self::isNoBreak($got)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Returns the first pattern (from a longest-first-sorted list) that matches at a,
     * implementing "longest match wins at a given a, with no backtracking" (nbsp.md 3.5) for
     * every list-driven sub-rule: N3, N4, N5, N6, N9, N10.
     *
     * @param array<int, int[]> $patterns
     * @return int[]|null
     */
    private static function longestMatch(array $patterns, array $cp, int $a, callable $matcher): ?array
    {
        foreach ($patterns as $w) {
            if ($matcher($cp, $a, $w)) {
                return $w;
            }
        }

        return null;
    }

    /**
     * N1 (3.3, beforePunctuation -> U+00A0) and N2 (3.4, narrowBeforePunctuation -> U+202F):
     * identical shape with target/other exchanged.
     *
     * @param array<string, mixed> $prep
     * @param array<int, Edit|null> $claims
     * @param int[] $marks
     */
    private static function punctuationSubRule(
        array $cp,
        array $prep,
        array &$claims,
        array $marks,
        int $target,
        int $other,
    ): void {
        if ($marks === []) {
            return;
        }

        $n = count($cp);
        for ($i = 0; $i < $n; $i++) {
            if (!in_array($cp[$i], $marks, true)) {
                continue;
            }

            $left = self::at($cp, $i - 1);
            // Step 1 -- run guard: only the first mark of "?!" or "!!!" takes the space.
            if ($left !== Sentinels::NONE && self::isMark($prep, $left)) {
                continue;
            }

            // Step 2 -- right-context guard: this is what protects "http://" and "12:30". U+2026
            // is accepted because the guard exists to catch punctuation *inside a token*, and an
            // ellipsis after a question mark is not that (nbsp.md 3.3 step 2). A span boundary
            // marker passes through isCloseish() (spec 1.2.0).
            $after = self::at($cp, $i + 1);
            if (
                $after !== Sentinels::NONE && !self::isSpaceLike($after) && !self::isCloseish($prep, $after) &&
                $after !== self::ELLIPSIS && !self::isMark($prep, $after)
            ) {
                continue;
            }

            // Step 3 -- quote-glyph guard. The space beside an opening quotation glyph is
            // quotes.innerSpace and belongs to N8 alone; without this N2 and N8 alternate for
            // ever on the French input "«?" (3.2, 3.10.1).
            if (self::isOpenish($prep, $left)) {
                continue;
            }
            if ($left !== Sentinels::NONE && self::isSpaceLike($left) && self::isOpenish($prep, self::at($cp, $i - 2))) {
                continue;
            }

            // Step 4 (spec 1.3.0) -- character-reference guard. text mode has no markup concept,
            // so a locale listing ";" used to insert before the ";" that *ends* a reference and
            // "Bonjour&#160;: oui" stopped being what it was (nbsp.md 3.3 step 4).
            if ($cp[$i] === self::SEMICOLON && self::endsCharacterReference($cp, $i)) {
                continue;
            }

            // Step 5.
            if ($left === $target) {
                continue;
            }

            if ($left === self::SPACE || $left === $other) {
                self::claimConversion($claims, $i - 1, $target);
                continue;
            }
            // A fixed-width space stays as typed, and nothing is inserted beside it.
            if (self::isOtherSpace($left)) {
                continue;
            }
            if ($left === Sentinels::NONE || self::isBreak($left) || $left === self::TAB) {
                continue;
            }

            self::claimInsertion($claims, $i, $target);
        }
    }

    /**
     * N3 -- nbsp.md 3.5 afterShortWords.
     *
     * @param array<string, mixed> $prep
     * @param array<int, Edit|null> $claims
     */
    private static function shortWordsSubRule(array $cp, array $prep, array &$claims): void
    {
        if ($prep['shortWords'] === []) {
            return;
        }

        $n = count($cp);
        for ($a = 0; $a < $n; $a++) {
            $w = self::longestMatch($prep['shortWords'], $cp, $a, self::matchFirstCharLenient(...));
            if ($w === null) {
                continue;
            }

            $k = count($w);
            $before = self::at($cp, $a - 1);
            if (
                !($before === Sentinels::NONE || self::isSpaceLike($before) || self::isOpenish($prep, $before) ||
                self::isSentenceDash($before))
            ) {
                continue;
            }

            $separator = self::at($cp, $a + $k);
            if ($separator === self::NBSP) {
                continue; // already correct
            }
            if ($separator !== self::SPACE) {
                continue;
            }

            $following = self::at($cp, $a + $k + 1);
            if (!self::isAlnum($following) && !self::isOpenish($prep, $following)) {
                continue;
            }

            self::claimConversion($claims, $a + $k, self::NBSP);
        }
    }

    /**
     * N4 -- nbsp.md 3.6 abbreviations, U+00A0 for every internal space.
     *
     * @param array<string, mixed> $prep
     * @param array<int, Edit|null> $claims
     */
    private static function abbreviationsSubRule(array $cp, array $prep, array &$claims): void
    {
        if ($prep['abbreviations'] === []) {
            return;
        }

        $n = count($cp);
        for ($a = 0; $a < $n; $a++) {
            $w = self::longestMatch($prep['abbreviations'], $cp, $a, self::matchSpaceLenient(...));
            if ($w === null) {
                continue;
            }

            $k = count($w);
            if (self::isAlnum(self::at($cp, $a - 1)) || self::isAlnum(self::at($cp, $a + $k))) {
                continue;
            }

            for ($j = 0; $j < $k; $j++) {
                if ($w[$j] !== self::SPACE) {
                    continue;
                }
                if ($cp[$a + $j] === self::NBSP) {
                    continue; // already correct at this internal position
                }

                self::claimConversion($claims, $a + $j, self::NBSP);
            }
        }
    }

    /**
     * N5 -- nbsp.md 3.7 beforeUnits. Converts an existing space; never inserts one (7.2).
     *
     * @param array<string, mixed> $prep
     * @param array<int, Edit|null> $claims
     */
    private static function unitsSubRule(array $cp, array $prep, array &$claims): void
    {
        if ($prep['units'] === []) {
            return;
        }

        $n = count($cp);
        for ($a = 0; $a < $n; $a++) {
            $w = self::longestMatch($prep['units'], $cp, $a, self::matchExact(...));
            if ($w === null) {
                continue;
            }

            $k = count($w);
            if (self::isAlnum(self::at($cp, $a + $k))) {
                continue;
            }

            $left = self::at($cp, $a - 1);
            if ($left === self::NBSP) {
                continue; // already correct
            }
            if ($left !== self::SPACE) {
                continue;
            }

            if (!self::isDigit(self::at($cp, $a - 2))) {
                continue;
            }

            $b = $a - 2;
            while ($b - 1 >= 0 && self::isDigit($cp[$b - 1])) {
                $b--;
            }
            // The letter guard: "H2 O", "A4", "MP3" are not measurements.
            if (UnicodeUtil::isLetter(self::at($cp, $b - 1))) {
                continue;
            }

            self::claimConversion($claims, $a - 1, self::NBSP);
        }
    }

    /**
     * N6 -- nbsp.md 3.8 afterSymbols. Conversion only.
     *
     * @param array<string, mixed> $prep
     * @param array<int, Edit|null> $claims
     */
    private static function symbolsSubRule(array $cp, array $prep, array &$claims): void
    {
        if ($prep['symbols'] === []) {
            return;
        }

        $n = count($cp);
        for ($a = 0; $a < $n; $a++) {
            $w = self::longestMatch($prep['symbols'], $cp, $a, self::matchExact(...));
            if ($w === null) {
                continue;
            }

            $k = count($w);
            if (self::isAlnum(self::at($cp, $a - 1))) {
                continue;
            }

            $separator = self::at($cp, $a + $k);
            if ($separator === self::NBSP) {
                continue; // already correct
            }
            if ($separator !== self::SPACE) {
                continue;
            }

            if (!self::isDigit(self::at($cp, $a + $k + 1))) {
                continue;
            }

            self::claimConversion($claims, $a + $k, self::NBSP);
        }
    }

    /**
     * nbsp.md 3.9: one uppercase letter, one full stop, at a token start.
     *
     * @param array<string, mixed> $prep
     */
    private static function isInitialAt(array $cp, array $prep, int $p): bool
    {
        if ($p < 0) {
            return false;
        }
        if (!UnicodeUtil::isUpper(self::at($cp, $p))) {
            return false;
        }
        if (self::at($cp, $p + 1) !== self::FULL_STOP) {
            return false;
        }

        $before = self::at($cp, $p - 1);

        return $before === Sentinels::NONE || self::isSpaceLike($before) || self::isOpenish($prep, $before);
    }

    /**
     * Guard C1-a (nbsp.md 3.9): an uppercase letter plus a dot that is itself preceded by a
     * lower-case letter plus a dot is the second token of an abbreviation, not an initial.
     * Without it the shipped de-DE data turns "z. B. Berlin" into a form with both the internal
     * abbreviation space AND the space after "B." bound to U+00A0 -- a false positive on ordinary
     * prose ("z. B." is correct, "B. Berlin" is not a name). "А. С. Пушкин" is unaffected:
     * cp[p-3] there is uppercase.
     */
    private static function isAbbreviationTail(array $cp, int $p): bool
    {
        if (!self::isSpaceLike(self::at($cp, $p - 1))) {
            return false;
        }
        if (self::at($cp, $p - 2) !== self::FULL_STOP) {
            return false;
        }

        $head = self::at($cp, $p - 3);

        return UnicodeUtil::isLetter($head) && !UnicodeUtil::isUpper($head);
    }

    /**
     * The "chain" mode confirmation (nbsp.md 3.9): is the initial whose letter sits at p itself
     * immediately preceded by another initial? Used only by "chain" mode's C1, to require
     * Chicago's own "two or more initials" before the space leading into a following non-initial
     * word (a candidate surname) is bound.
     *
     * @param array<string, mixed> $prep
     */
    private static function precedingInitial(array $cp, array $prep, int $p): bool
    {
        $gap = self::at($cp, $p - 1);
        if ($gap !== self::SPACE && $gap !== self::NBSP) {
            return false;
        }
        if (self::at($cp, $p - 2) !== self::FULL_STOP) {
            return false;
        }

        return self::isInitialAt($cp, $prep, $p - 3);
    }

    /**
     * N7 -- nbsp.md 3.9 initialBinding, skipped entirely when the locale's initialBinding is
     * "none".
     *
     * @param array<string, mixed> $prep
     * @param array<int, Edit|null> $claims
     */
    private static function initialsSubRule(array $cp, array $prep, array &$claims): void
    {
        $mode = $prep['initialBinding'];
        if ($mode === 'none') {
            return;
        }

        $n = count($cp);
        for ($q = 0; $q < $n; $q++) {
            $here = $cp[$q];
            if ($here !== self::SPACE && $here !== self::NBSP) {
                continue;
            }

            // C1 -- an initial on the left and an uppercase letter on the right, unless C1-a
            // declines.
            $leftInitialP = $q - 2;
            $c1Shape = self::at($cp, $q - 1) === self::FULL_STOP &&
                self::isInitialAt($cp, $prep, $leftInitialP) &&
                UnicodeUtil::isUpper(self::at($cp, $q + 1)) &&
                !self::isAbbreviationTail($cp, $leftInitialP);
            // "chain" mode additionally requires either that the right side is itself an initial
            // (the between-initials case, e.g. "E.|B.", always safe) or that the left initial is
            // itself preceded by another initial (a confirmed chain of two or more, e.g.
            // "E. B.|White") before binding to a plain following word. "single" mode keeps the
            // unconditional shape check -- the behaviour fr/fr-CA need for "N. Bourbaki"/
            // "M. Dupont" (nbsp.md 3.9, Jacques André), structurally indistinguishable from a
            // sentence-boundary collision.
            $c1 = $c1Shape && (
                $mode === 'single' ||
                self::isInitialAt($cp, $prep, $q + 1) ||
                self::precedingInitial($cp, $prep, $leftInitialP)
            );

            // C2 -- a word on the left and two consecutive initials on the right
            // ("Пушкин А. С."). Already requires two initials by construction, so it is
            // unaffected by "chain" vs "single".
            $rightSpace = self::at($cp, $q + 3);
            $c2 = UnicodeUtil::isLetter(self::at($cp, $q - 1)) &&
                self::isInitialAt($cp, $prep, $q + 1) &&
                ($rightSpace === self::SPACE || $rightSpace === self::NBSP) &&
                self::isInitialAt($cp, $prep, $q + 4);

            if (!$c1 && !$c2) {
                continue;
            }
            if ($here === self::NBSP) {
                continue; // already correct
            }

            self::claimConversion($claims, $q, self::NBSP);
        }
    }

    /**
     * N8 -- nbsp.md 3.10 quotes.innerSpace. The only sub-rule besides N1/N2 that may insert.
     *
     * @param array<string, mixed> $prep
     * @param array<int, Edit|null> $claims
     */
    private static function quotesSubRule(array $cp, array $prep, array &$claims): void
    {
        $n = count($cp);
        foreach ($prep['quotePairs'] as $pair) {
            for ($i = 0; $i < $n; $i++) {
                $here = $cp[$i];

                if ($here === $pair['open']) {
                    $right = self::at($cp, $i + 1);
                    if ($right === $pair['target']) {
                        // already correct
                    } elseif ($right === self::SPACE || self::isNoBreak($right)) {
                        self::claimConversion($claims, $i + 1, $pair['target']);
                    } elseif ($right === Sentinels::NONE || self::isBreak($right)) {
                        // skip: never insert at a line boundary or the end of the text
                    } else {
                        self::claimInsertion($claims, $i + 1, $pair['target']);
                    }
                    continue;
                }

                if ($here !== $pair['close']) {
                    continue;
                }

                $left = self::at($cp, $i - 1);
                if ($left === $pair['target']) {
                    // already correct
                } elseif ($left === self::SPACE || self::isNoBreak($left)) {
                    self::claimConversion($claims, $i - 1, $pair['target']);
                } elseif ($left === Sentinels::NONE || self::isBreak($left)) {
                    // skip
                } else {
                    self::claimInsertion($claims, $i, $pair['target']);
                }
            }
        }
    }

    /**
     * N9 (nbsp.md 3.11, beforeNumber, wantsDigit = true) and N10 (3.12, beforeWord, wantsDigit =
     * false). They share every guard except what must follow the separator: a digit for N9, a
     * letter for N10.
     *
     * @param array<string, mixed> $prep
     * @param array<int, Edit|null> $claims
     * @param array<int, int[]> $patterns
     */
    private static function forwardBindingSubRule(
        array $cp,
        array $prep,
        array &$claims,
        array $patterns,
        bool $wantsDigit,
    ): void {
        if ($patterns === []) {
            return;
        }

        $n = count($cp);
        for ($a = 0; $a < $n; $a++) {
            $w = self::longestMatch($patterns, $cp, $a, self::matchExact(...));
            if ($w === null) {
                continue;
            }

            $k = count($w);

            // G-D (3.12 step 5, N10 only): in a locale where N7 is active, an *uppercase* letter
            // plus a dot is structurally an initial, and N7 owns that shape with better evidence
            // (it inspects what follows for a second initial or a surname). The UPPER test is
            // load-bearing: without it a lower-case entry such as "ул." would be inert in an
            // initialBinding-active locale.
            if (
                !$wantsDigit && $prep['initialBinding'] !== 'none' && $k === 2 &&
                UnicodeUtil::isUpper($w[0]) && $w[1] === self::FULL_STOP
            ) {
                continue;
            }

            // G-L -- stronger than "not ALNUM": it is what stops "S." matching inside "Fig.S. 3".
            // A hyphen fails it, per 3.5 step 2 -- an abbreviation cannot begin immediately after
            // an intra-word hyphen.
            $before = self::at($cp, $a - 1);
            if (
                !($before === Sentinels::NONE || self::isSpaceLike($before) || self::isOpenish($prep, $before) ||
                self::isSentenceDash($before))
            ) {
                continue;
            }

            // G-S -- exactly one separator, and it must already be a space.
            $separator = self::at($cp, $a + $k);
            if ($separator === self::NBSP) {
                continue; // already correct
            }
            if ($separator !== self::SPACE) {
                continue;
            }

            $following = self::at($cp, $a + $k + 1);
            if (self::isSpaceLike($following)) {
                continue;
            }

            // G-W / "a following number": one code point, tested for membership. NONE fails
            // both, which is also the line-boundary guard G-B.
            if ($wantsDigit) {
                if (!self::isDigit($following)) {
                    continue;
                }
            } elseif (!UnicodeUtil::isLetter($following)) {
                continue;
            }

            self::claimConversion($claims, $a + $k, self::NBSP);
        }
    }

    /**
     * nbsp.md 3.2: N1 through N10, in that fixed order, first claim wins. The order is positional
     * and total, never an artefact of associative-array iteration.
     *
     * First-claim-wins only settles a conflict when both sub-rules actually emit an edit; an
     * "already correct" branch emits nothing and therefore claims nothing, silently yielding the
     * index to a lower-priority sub-rule. Sub-rules wanting *different* code points at a shared
     * index are therefore made disjoint by construction elsewhere (N1/N2's quote-glyph guard,
     * nbsp.md 3.10.1) rather than relying on ordering alone.
     *
     * @param int[] $cp
     * @param array<string, mixed> $localeData
     * @return Edit[]
     */
    public static function scan(array $cp, array $localeData, RuleContext $ctx): array
    {
        $prep = self::prepare($localeData, $ctx->narrowTarget);
        $claims = array_fill(0, count($cp) + 1, null);

        self::punctuationSubRule($cp, $prep, $claims, $prep['beforePunctuation'], self::NBSP, self::NNBSP); // N1
        // N2's target is NARROW-TARGET (nbsp.md 3.1a); the last argument is the NOBREAK member
        // that is not the target, which is what the sub-rule converts. With the substitution on,
        // N2 and N1 want the same character -- never different ones.
        $narrowOther = $ctx->narrowTarget === self::NBSP ? self::NNBSP : self::NBSP;
        self::punctuationSubRule($cp, $prep, $claims, $prep['narrowBeforePunctuation'], $ctx->narrowTarget, $narrowOther); // N2
        self::shortWordsSubRule($cp, $prep, $claims);                                                             // N3
        self::abbreviationsSubRule($cp, $prep, $claims);                                                          // N4
        self::unitsSubRule($cp, $prep, $claims);                                                                  // N5
        self::symbolsSubRule($cp, $prep, $claims);                                                                // N6
        self::initialsSubRule($cp, $prep, $claims);                                                               // N7
        self::quotesSubRule($cp, $prep, $claims);                                                                 // N8
        self::forwardBindingSubRule($cp, $prep, $claims, $prep['beforeNumber'], true);                            // N9
        self::forwardBindingSubRule($cp, $prep, $claims, $prep['beforeWord'], false);                             // N10

        $edits = [];
        foreach ($claims as $claim) {
            if ($claim !== null) {
                $edits[] = $claim;
            }
        }

        return $edits;
    }
}
