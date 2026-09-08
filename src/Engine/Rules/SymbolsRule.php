<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Edit;
use Polytypo\Engine\RuleContext;
use Polytypo\Engine\Sentinels;
use Polytypo\Engine\UnicodeUtil;

/**
 * `symbols` -- spec/rules/symbols.md (spec 0.1.0), order 60.
 *
 * Three unrelated substitutions, one left-to-right scan: `(c)`/`(r)`/`(tm)` literals become
 * (c)/(r)/(tm) signs (3.2); a `DIGIT+ (MUL-LETTER DIGIT+)+` chain becomes multiplication signs,
 * converted whole or not at all (3.3); the literal `+/-` becomes plus-minus (3.4). No locale
 * data -- order.json declares "localeData": [] for this rule. No regex, no case folding anywhere
 * (ARCHITECTURE.md section 4.1, 4.4): the accepted spellings are enumerated explicitly rather
 * than derived from a locale-dependent uppercase/lowercase operation (Turkish dotless i).
 */
final class SymbolsRule
{
    private const PAREN_OPEN = 0x28;
    private const PAREN_CLOSE = 0x29;
    private const SQUARE_OPEN = 0x5B;
    private const SQUARE_CLOSE = 0x5D;

    private const COPYRIGHT = 0xA9;
    private const REGISTERED = 0xAE;
    private const TRADEMARK = 0x2122;
    private const MULTIPLICATION = 0xD7;

    private const SPACE = 0x20;
    private const NBSP = 0xA0;
    private const NNBSP = 0x202F;

    private const LOWER_X = 0x78;
    private const UPPER_X = 0x58;
    private const CYRILLIC_LOWER_HA = 0x445;
    private const CYRILLIC_UPPER_HA = 0x425;
    private const DIGIT_ZERO = 0x30;
    private const DIGIT_NINE = 0x39;

    private const PLUS = 0x2B;
    private const SOLIDUS = 0x2F;
    private const HYPHEN_MINUS = 0x2D;
    private const PLUS_MINUS = 0xB1;

    /**
     * Trademark table rows (symbols.md 3.1-3.2), exhaustive and case-explicit; nothing else
     * matches. Longest literals first (the 4-code-point (tm) rows before the 3-code-point (c)/(r)
     * rows), per 3.2 step 1. Each row is [literal (int[]), replacement (int), guardedByS1 (bool)];
     * guardedByS1 is true only for the (c)/(r) rows -- the (tm) rows are exempt from S1 (3.2
     * step 3).
     *
     * @var array<int, array{0: int[], 1: int, 2: bool}>
     */
    private const TRADEMARK_TABLE = [
        [[self::PAREN_OPEN, 0x74, 0x6D, self::PAREN_CLOSE], self::TRADEMARK, false], // (tm)
        [[self::PAREN_OPEN, 0x54, 0x4D, self::PAREN_CLOSE], self::TRADEMARK, false], // (TM)
        [[self::PAREN_OPEN, 0x54, 0x6D, self::PAREN_CLOSE], self::TRADEMARK, false], // (Tm)
        [[self::PAREN_OPEN, 0x74, 0x4D, self::PAREN_CLOSE], self::TRADEMARK, false], // (tM)
        [[self::PAREN_OPEN, 0x63, self::PAREN_CLOSE], self::COPYRIGHT, true],        // (c)
        [[self::PAREN_OPEN, 0x43, self::PAREN_CLOSE], self::COPYRIGHT, true],        // (C)
        [[self::PAREN_OPEN, 0x72, self::PAREN_CLOSE], self::REGISTERED, true],       // (r)
        [[self::PAREN_OPEN, 0x52, self::PAREN_CLOSE], self::REGISTERED, true],       // (R)
    ];

    private function __construct()
    {
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
     * SPACE u NOBREAK-SPACE (symbols.md 3.1): U+0020, U+00A0, U+202F only -- no tabs, no other
     * Unicode spaces, no line breaks. Narrower than nbsp's SPACELIKE on purpose: this rule only
     * needs the spacing a multiplication chain can legally carry.
     */
    private static function isSpaceLike(int $cp): bool
    {
        return $cp === self::SPACE || $cp === self::NBSP || $cp === self::NNBSP;
    }

    /**
     * MUL-LETTER (symbols.md 3.1): exactly four code points, enumerated, never case-folded and
     * never derived from locale data. The Cyrillic pair is unconditional -- U+0445 between two
     * ASCII digits is a Russian dimension typed on a Cyrillic layout or keyboard-layout debris,
     * and the glyphs are visually identical to the Latin ones in every font, so no human review
     * can catch a missed conversion.
     */
    private static function isMulLetter(int $cp): bool
    {
        return $cp === self::LOWER_X || $cp === self::UPPER_X ||
            $cp === self::CYRILLIC_LOWER_HA || $cp === self::CYRILLIC_UPPER_HA;
    }

    /** @param int[] $literal */
    private static function matchesAt(array $cp, int $i, array $literal): bool
    {
        if ($i + count($literal) > count($cp)) {
            return false;
        }

        foreach ($literal as $j => $want) {
            if (self::at($cp, $i + $j) !== $want) {
                return false;
            }
        }

        return true;
    }

    /**
     * symbols.md 3.2. Returns the Edit, or null when no row matches or a guard rejects the
     * candidate.
     */
    private static function trademarkAt(array $cp, int $i): ?Edit
    {
        foreach (self::TRADEMARK_TABLE as [$literal, $to, $guardedByS1]) {
            if (!self::matchesAt($cp, $i, $literal)) {
                continue;
            }

            $endIndex = $i + count($literal);
            $before = self::at($cp, $i - 1);
            $after = self::at($cp, $endIndex);

            // S1 -- left adjacency, (c)/(r) rows only: a one-letter argument list ("f(c)") is
            // common, "(tm)" tucked against a product name is not a call. The three replacement
            // signs are listed so that "(c)(r)" converges to "(c)(r)" in one run (symbols.md 5).
            if (
                $guardedByS1 && $before !== Sentinels::NONE &&
                (self::isAlnum($before) || $before === self::PAREN_CLOSE || $before === self::SQUARE_CLOSE ||
                    $before === self::COPYRIGHT || $before === self::REGISTERED || $before === self::TRADEMARK)
            ) {
                return null;
            }
            // S2 -- right adjacency: "(r)evolution", "(c)ompiler".
            if ($after !== Sentinels::NONE && self::isAlnum($after)) {
                return null;
            }
            // S3 -- no nesting: "((c))" is ASCII art or code.
            if ($before === self::PAREN_OPEN) {
                return null;
            }

            return new Edit($i, $endIndex, [$to], 'symbols');
        }

        return null;
    }

    /**
     * Reads the chain greedily and unconditionally; guard decisions happen afterward in
     * chainEdits, never here, so the scan shape itself cannot express "how many links
     * converted" -- only "whole chain or nothing" (symbols.md 3.3 step 2, 5).
     *
     * Returns [endIndex, firstRunEnd, links]. endIndex is the last code point read, whether or
     * not any link was completed -- the caller resumes scanning at endIndex + 1 either way.
     * firstRunEnd is the last digit of the very first digit run, used only by guard M4. Each
     * link is [letterIndex, leftSpace, rightSpace] (leftSpace/rightSpace are 0 or 1).
     *
     * @return array{0: int, 1: int, 2: array<int, array{0: int, 1: int, 2: int}>}
     */
    private static function readChain(array $cp, int $a): array
    {
        $p = $a;
        while (self::isDigit(self::at($cp, $p))) {
            $p++;
        }
        $firstRunEnd = $p - 1;
        $endIndex = $p - 1;
        $links = [];

        while (true) {
            $q = $p;
            $leftSpace = 0;
            if (self::isSpaceLike(self::at($cp, $q))) {
                $leftSpace = 1;
                $q++;
            }
            if (!self::isMulLetter(self::at($cp, $q))) {
                break;
            }

            $letterIndex = $q;
            $q++;
            $rightSpace = 0;
            if (self::isSpaceLike(self::at($cp, $q))) {
                $rightSpace = 1;
                $q++;
            }
            if (!self::isDigit(self::at($cp, $q))) {
                break;
            }

            while (self::isDigit(self::at($cp, $q))) {
                $q++;
            }
            $links[] = [$letterIndex, $leftSpace, $rightSpace];
            $endIndex = $q - 1;
            $p = $q;
        }

        return [$endIndex, $firstRunEnd, $links];
    }

    /**
     * Applies guards M1-M4 to a chain read by readChain. Returns the edits, or null when the
     * chain is declined whole -- symbols.md 3.3 never half-converts a chain.
     *
     * @param array{0: int, 1: int, 2: array<int, array{0: int, 1: int, 2: int}>} $chain
     * @return Edit[]|null
     */
    private static function chainEdits(array $cp, int $a, array $chain): ?array
    {
        [$chainEnd, $firstRunEnd, $links] = $chain;
        if ($links === []) {
            return null;
        }
        $first = $links[0];
        [$firstLetterIndex, $firstLeftSpace] = $first;

        // M1 -- every link symmetric, and every link agreeing with the first. "5x4 x 3" is
        // ambiguous input and is declined whole rather than half-converted.
        $sp = $firstLeftSpace;
        foreach ($links as $link) {
            [, $leftSpace, $rightSpace] = $link;
            if ($leftSpace !== $rightSpace) {
                return null;
            }
            if ($leftSpace !== $sp) {
                return null;
            }
        }

        // M2/M3 -- the chain's OUTER boundaries, not each link. Applying them per link is
        // exactly what made the pairwise form reject chains: the letter past the middle digit
        // run is itself a MUL-LETTER, hence a LETTER.
        $before = self::at($cp, $a - 1);
        $after = self::at($cp, $chainEnd + 1);
        if ($before !== Sentinels::NONE && UnicodeUtil::isLetter($before)) {
            return null;
        }
        if ($after !== Sentinels::NONE && UnicodeUtil::isLetter($after)) {
            return null;
        }

        // M4 -- hexadecimal literal veto. Latin lowercase only: a hex literal is never written
        // with Cyrillic. Inspects the first link alone.
        if (
            $sp === 0 && self::at($cp, $firstLetterIndex) === self::LOWER_X &&
            $firstRunEnd === $a && self::at($cp, $a) === self::DIGIT_ZERO
        ) {
            return null;
        }

        // There is no M5. It was removed rather than extended (symbols.md 3.3 step 7, 7.3).
        $edits = [];
        foreach ($links as $link) {
            $j = $link[0];
            $replacement = $sp === 0
                ? [self::MULTIPLICATION]
                : [self::at($cp, $j - 1), self::MULTIPLICATION, self::at($cp, $j + 1)];
            $startIndex = $j - $sp;
            $endSpan = $j + $sp + 1;
            if (self::sameSpan($cp, $startIndex, $endSpan, $replacement)) {
                continue;
            }

            $edits[] = new Edit($startIndex, $endSpan, $replacement, 'symbols');
        }

        return $edits;
    }

    /** @param int[] $replacement */
    private static function sameSpan(array $cp, int $start, int $end, array $replacement): bool
    {
        if ($end - $start !== count($replacement)) {
            return false;
        }
        foreach ($replacement as $j => $want) {
            if (self::at($cp, $start + $j) !== $want) {
                return false;
            }
        }

        return true;
    }

    /**
     * symbols.md 3.4. Only the literal "+/-"; the bare "+-" is never converted, in any context
     * (3.4, 7.11).
     */
    private static function plusMinusAt(array $cp, int $i): ?Edit
    {
        if (self::at($cp, $i + 1) !== self::SOLIDUS || self::at($cp, $i + 2) !== self::HYPHEN_MINUS) {
            return null;
        }

        // F1 -- not a character class: "[+/-]" is a regular expression.
        if (self::at($cp, $i - 1) === self::SQUARE_OPEN) {
            return null;
        }

        // F2 -- numeric context, with one optional intervening space so "+/-5" and "+/- 5" both
        // work. Without it, prose that names the characters ("lines marked +/- were edited") is
        // corrupted.
        $j = $i + 3;
        if (self::at($cp, $j) === self::SPACE) {
            $j++;
        }
        if (!self::isDigit(self::at($cp, $j))) {
            return null;
        }

        return new Edit($i, $i + 3, [self::PLUS_MINUS], 'symbols');
    }

    /**
     * symbols.md 3.5: one left-to-right scan. The three branches key on different code points --
     * U+0028, a DIGIT, U+002B -- so no two can match at the same index; on a successful edit the
     * scan continues from the index after the matched span.
     *
     * @param int[] $cp
     * @param array<string, mixed> $localeData unused: order.json declares "localeData": [] for
     *     this rule.
     * @return Edit[]
     */
    public static function scan(array $cp, array $localeData, RuleContext $ctx): array
    {
        $n = count($cp);
        $edits = [];
        $i = 0;

        while ($i < $n) {
            $current = self::at($cp, $i);

            if ($current === self::PAREN_OPEN) {
                $edit = self::trademarkAt($cp, $i);
                if ($edit !== null) {
                    $edits[] = $edit;
                    $i = $edit->end;
                    continue;
                }
            } elseif (self::isDigit($current) && !self::isDigit(self::at($cp, $i - 1))) {
                // Keyed on the start of a maximal digit run, not on the letter.
                $chain = self::readChain($cp, $i);
                $chainResult = self::chainEdits($cp, $i, $chain);
                if ($chainResult !== null) {
                    foreach ($chainResult as $edit) {
                        $edits[] = $edit;
                    }
                }
                // Continue past the chain whether or not it converted. A declined chain has no
                // convertible sub-chain: any sub-chain starts right after a MUL-LETTER, which is
                // a LETTER, so M2 would reject it too.
                $i = $chain[0] + 1;
                continue;
            } elseif ($current === self::PLUS) {
                $edit = self::plusMinusAt($cp, $i);
                if ($edit !== null) {
                    $edits[] = $edit;
                    $i = $edit->end;
                    continue;
                }
            }
            $i++;
        }

        return $edits;
    }
}
