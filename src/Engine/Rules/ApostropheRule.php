<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Edit;
use Polytypo\Engine\RuleContext;
use Polytypo\Engine\Sentinels;
use Polytypo\Engine\UnicodeUtil;

/**
 * spec/rules/apostrophe.md (spec 1.2.0), order 50.
 *
 * Converts a straight U+0027 to U+2019 where it is genuinely an apostrophe: a contraction, an
 * elision, a possessive, or a decade elision. Runs immediately after `quotes` (order 40) and sees
 * only the U+0027 marks quotes declined to claim. Every edit is one code point replacing one code
 * point; the rule never inserts, never deletes, and never touches U+2019 itself.
 *
 * As of spec 1.1.0 this rule reads no locale data and skips no position (apostrophe.md 3.4).
 * Spec 0.5.0's preserve set existed to stop the case ladder from converting the marks `quotes`
 * had vetoed; conversion is now the specified outcome for exactly those marks.
 */
final class ApostropheRule
{
    private const STRAIGHT_APOSTROPHE = 0x27;
    private const RIGHT_SINGLE_QUOTATION_MARK = 0x2019;

    // OPENISH -- apostrophe.md 3.1 OPENISH. Unlike quotes.md's OPENISH, this is not "every
    // QUOTEMARK" -- only the specific opening-shaped glyphs the spec lists. Sentinels::MARKER is
    // a member (modes.md 3.3's table names this rule explicitly).
    private const PAREN_OPEN = 0x28;
    private const SQUARE_OPEN = 0x5B;
    private const CURLY_OPEN = 0x7B;
    private const LEFT_POINTING_DOUBLE_ANGLE_QUOTATION_MARK = 0xAB;
    private const LEFT_SINGLE_QUOTATION_MARK = 0x2018;
    private const SINGLE_LOW_9_QUOTATION_MARK = 0x201A;
    private const SINGLE_HIGH_REVERSED_9_QUOTATION_MARK = 0x201B;
    private const LEFT_DOUBLE_QUOTATION_MARK = 0x201C;
    private const DOUBLE_LOW_9_QUOTATION_MARK = 0x201E;
    private const DOUBLE_HIGH_REVERSED_9_QUOTATION_MARK = 0x201F;
    private const SINGLE_LEFT_POINTING_ANGLE_QUOTATION_MARK = 0x2039;
    private const HYPHEN_MINUS = 0x2D;
    private const NON_BREAKING_HYPHEN = 0x2011;
    private const EN_DASH = 0x2013;
    private const EM_DASH = 0x2014;

    // CLOSEISH -- apostrophe.md 3.1 CLOSEISH. U+2019 is a member and U+0027 is a member of
    // neither this nor OPENISH -- apostrophe.md 5 turns exactly that asymmetry into the
    // idempotency argument. U+2011 sits beside U+002D because `hyphen` (order 35) converts one to
    // the other.
    private const PAREN_CLOSE = 0x29;
    private const SQUARE_CLOSE = 0x5D;
    private const CURLY_CLOSE = 0x7D;
    private const RIGHT_POINTING_DOUBLE_ANGLE_QUOTATION_MARK = 0xBB;
    private const RIGHT_DOUBLE_QUOTATION_MARK = 0x201D;
    private const SINGLE_RIGHT_POINTING_ANGLE_QUOTATION_MARK = 0x203A;
    private const COMMA = 0x2C;
    private const FULL_STOP = 0x2E;
    private const SEMICOLON = 0x3B;
    private const COLON = 0x3A;
    private const EXCLAMATION = 0x21;
    private const QUESTION = 0x3F;
    private const ELLIPSIS = 0x2026;

    // BREAK, including Sentinels::LINE_MARKER as a member of BREAK for every rule everywhere
    // (modes.md 3.2).
    private const LF = 0x0A;
    private const CR = 0x0D;
    private const VT = 0x0B;
    private const FF = 0x0C;
    private const NEL = 0x85;
    private const LS = 0x2028;
    private const PS = 0x2029;

    private const DIGIT_ZERO = 0x30;
    private const DIGIT_NINE = 0x39;

    private function __construct()
    {
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

    private static function isDigit(int $cp): bool
    {
        return $cp >= self::DIGIT_ZERO && $cp <= self::DIGIT_NINE;
    }

    private static function isAlnum(int $cp): bool
    {
        return self::isDigit($cp) || UnicodeUtil::isLetter($cp);
    }

    private static function isOpenish(int $cp): bool
    {
        return $cp === Sentinels::MARKER
            || $cp === self::PAREN_OPEN
            || $cp === self::SQUARE_OPEN
            || $cp === self::CURLY_OPEN
            || $cp === self::LEFT_POINTING_DOUBLE_ANGLE_QUOTATION_MARK
            || $cp === self::LEFT_SINGLE_QUOTATION_MARK
            || $cp === self::SINGLE_LOW_9_QUOTATION_MARK
            || $cp === self::SINGLE_HIGH_REVERSED_9_QUOTATION_MARK
            || $cp === self::LEFT_DOUBLE_QUOTATION_MARK
            || $cp === self::DOUBLE_LOW_9_QUOTATION_MARK
            || $cp === self::DOUBLE_HIGH_REVERSED_9_QUOTATION_MARK
            || $cp === self::SINGLE_LEFT_POINTING_ANGLE_QUOTATION_MARK
            || $cp === self::HYPHEN_MINUS
            || $cp === self::NON_BREAKING_HYPHEN
            || $cp === self::EN_DASH
            || $cp === self::EM_DASH;
    }

    /**
     * OPENQUOTE -- apostrophe.md 3.1 (spec 1.2.0): the quotation glyphs of OPENISH, without its
     * brackets, dashes and Sentinels::MARKER. Case 3 already accepts the marker through CLOSEISH,
     * so membership here would change nothing (modes.md 3.3).
     */
    private static function isOpenQuote(int $cp): bool
    {
        return $cp === self::LEFT_POINTING_DOUBLE_ANGLE_QUOTATION_MARK
            || $cp === self::LEFT_SINGLE_QUOTATION_MARK
            || $cp === self::SINGLE_LOW_9_QUOTATION_MARK
            || $cp === self::SINGLE_HIGH_REVERSED_9_QUOTATION_MARK
            || $cp === self::LEFT_DOUBLE_QUOTATION_MARK
            || $cp === self::DOUBLE_LOW_9_QUOTATION_MARK
            || $cp === self::DOUBLE_HIGH_REVERSED_9_QUOTATION_MARK
            || $cp === self::SINGLE_LEFT_POINTING_ANGLE_QUOTATION_MARK;
    }

    private static function isCloseish(int $cp): bool
    {
        return $cp === Sentinels::MARKER
            || $cp === self::PAREN_CLOSE
            || $cp === self::SQUARE_CLOSE
            || $cp === self::CURLY_CLOSE
            || $cp === self::RIGHT_POINTING_DOUBLE_ANGLE_QUOTATION_MARK
            || $cp === self::RIGHT_SINGLE_QUOTATION_MARK
            || $cp === self::RIGHT_DOUBLE_QUOTATION_MARK
            || $cp === self::SINGLE_RIGHT_POINTING_ANGLE_QUOTATION_MARK
            || $cp === self::COMMA
            || $cp === self::FULL_STOP
            || $cp === self::SEMICOLON
            || $cp === self::COLON
            || $cp === self::EXCLAMATION
            || $cp === self::QUESTION
            || $cp === self::ELLIPSIS
            || $cp === self::HYPHEN_MINUS
            || $cp === self::NON_BREAKING_HYPHEN
            || $cp === self::EN_DASH
            || $cp === self::EM_DASH;
    }

    /**
     * CLOSEDELIM -- apostrophe.md 3.1 (spec 1.5.0, case 2a): the bracket and quotation members
     * of CLOSEISH, without its sentence punctuation and without the dashes OPENISH already
     * carries. These are exactly the closing delimiters case 3 has always accepted on the mark's
     * RIGHT; before 1.5.0 no left-hand test accepted any of them. Sentinels::MARKER is not a
     * member (modes.md 3.3): it is in OPENISH, so a mark against a span boundary already reaches
     * case 4 and emits the same U+2019.
     */
    private static function isCloseDelim(int $cp): bool
    {
        return $cp === self::PAREN_CLOSE
            || $cp === self::SQUARE_CLOSE
            || $cp === self::CURLY_CLOSE
            || $cp === self::RIGHT_POINTING_DOUBLE_ANGLE_QUOTATION_MARK
            || $cp === self::RIGHT_SINGLE_QUOTATION_MARK
            || $cp === self::RIGHT_DOUBLE_QUOTATION_MARK
            || $cp === self::SINGLE_RIGHT_POINTING_ANGLE_QUOTATION_MARK;
    }

    /**
     * The case ladder of apostrophe.md 3.3, first match wins. Every verdict is a pure function of
     * exactly two neighbouring code points; there is no lookahead and no state carried between
     * candidates.
     */
    private static function isApostrophe(int $left, int $right): bool
    {
        // Case 1 -- prime guard, first so it wins over case 3: `6' 2"`, `55 deg 40' N`, `6'2"`. A
        // foot mark is not an apostrophe.
        if (self::isDigit($left) && !UnicodeUtil::isLetter($right)) {
            return false;
        }

        // Case 2 -- medial apostrophe: `don't`, `l'ete`, `O'Brien`, `1990's`.
        if (self::isAlnum($left) && self::isAlnum($right)) {
            return true;
        }

        // Case 2a -- suffix or possessive after a closing delimiter (spec 1.5.0):
        // `(order 90)'s`, `“Hamlet”'s`, `{user}'s`. Disjoint from every other case, so its
        // position in the ladder carries no behaviour.
        if (self::isCloseDelim($left) && self::isAlnum($right)) {
            return true;
        }

        // Case 3 -- trailing elision or possessive: `the dogs' bowls`, `Jesus'`, `rock 'n'` (the
        // trailing mark).
        if (
            $left !== Sentinels::NONE && UnicodeUtil::isLetter($left)
            && ($right === Sentinels::NONE || self::isSpacelike($right) || self::isCloseish($right))
        ) {
            return true;
        }

        // Case 3a -- elision before a quotation (spec 1.2.0): `d'« urine »`, `l'“idea”`. Reads no
        // locale data; `(` is not in OPENQUOTE, so `f'(x)` stays a prime.
        if (UnicodeUtil::isLetter($left) && self::isOpenQuote($right)) {
            return true;
        }

        // Case 4 -- leading elision: `'90s`, `'tis`, `'em`, `'n'` (the leading mark). The
        // replacement is U+2019, never U+2018 -- a leading elision is a raised comma, not an
        // opening quotation mark, and `quotes` has already had its chance to claim the mark as a
        // quotation and declined (quotes.md 3.2, 5).
        if (($left === Sentinels::NONE || self::isSpacelike($left) || self::isOpenish($left)) && self::isAlnum($right)) {
            return true;
        }

        // Case 5 -- nothing inferable: `a ' b`, `''`. Leave it.
        return false;
    }

    /**
     * @param int[] $cp
     * @param array<string, mixed> $localeData
     * @return Edit[]
     */
    public static function scan(array $cp, array $localeData, RuleContext $ctx): array
    {
        $n = count($cp);

        $edits = [];
        for ($i = 0; $i < $n; $i++) {
            if ($cp[$i] !== self::STRAIGHT_APOSTROPHE) {
                continue;
            }

            $left = QuoteAmbiguity::at($cp, $i - 1);
            $right = QuoteAmbiguity::at($cp, $i + 1);
            if (self::isApostrophe($left, $right)) {
                $edits[] = new Edit($i, $i + 1, [self::RIGHT_SINGLE_QUOTATION_MARK], 'apostrophe');
            }
        }

        return $edits;
    }
}
