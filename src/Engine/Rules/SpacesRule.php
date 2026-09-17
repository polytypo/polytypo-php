<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Edit;
use Polytypo\Engine\RuleContext;
use Polytypo\Engine\Sentinels;
use Polytypo\Engine\UnicodeUtil;

/**
 * spec/rules/spaces.md. No regex anywhere (ARCHITECTURE.md section 4.1): a single left-to-right
 * scan over the code-point array with explicit lookaround by index. Reads no locale data
 * (spec/rules/spaces.md section 2): behaviour is identical in every locale.
 */
final class SpacesRule
{
    private const SPACE = 0x20;

    private const LF = 0x0A;
    private const CR = 0x0D;
    private const VT = 0x0B;
    private const FF = 0x0C;
    private const NEL = 0x85;
    private const LS = 0x2028;
    private const PS = 0x2029;

    private const COMMA = 0x2C;
    private const FULL_STOP = 0x2E;
    private const SEMICOLON = 0x3B;
    private const COLON = 0x3A;
    private const EXCLAMATION = 0x21;
    private const QUESTION = 0x3F;
    private const ELLIPSIS = 0x2026;

    private const PAREN_OPEN = 0x28;
    private const PAREN_CLOSE = 0x29;
    private const SQUARE_OPEN = 0x5B;
    private const SQUARE_CLOSE = 0x5D;
    private const CURLY_OPEN = 0x7B;
    private const CURLY_CLOSE = 0x7D;

    private const HYPHEN_MINUS = 0x2D;
    private const CARET = 0x5E;
    private const SOLIDUS = 0x2F;
    private const REVERSE_SOLIDUS = 0x5C;
    private const VERTICAL_LINE = 0x7C;
    private const ASTERISK = 0x2A;
    private const DIGIT_ZERO = 0x30;
    private const DIGIT_NINE = 0x39;
    private const LETTER_D_UPPER = 0x44;
    private const LETTER_D_LOWER = 0x64;
    private const LETTER_P_UPPER = 0x50;
    private const LETTER_P_LOWER = 0x70;
    private const LETTER_O_UPPER = 0x4F;
    private const LETTER_O_LOWER = 0x6F;

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

    /**
     * BREAK (spaces.md 3.1), including LINE_MARKER: a member of BREAK for every rule, everywhere
     * (modes.md 3.2).
     */
    private static function isBreak(int $value): bool
    {
        return $value === self::LF || $value === self::CR || $value === self::VT || $value === self::FF ||
            $value === self::NEL || $value === self::LS || $value === self::PS ||
            $value === Sentinels::LINE_MARKER;
    }

    /**
     * STRIP-BEFORE: exactly six code points. U+2026 is deliberately absent -- with it,
     * "Wait ..." would keep its space here, `ellipsis` would yield "Wait …", and a second
     * pipeline pass would then strip that space, a composition divergence (spaces.md 3.4).
     */
    private static function isStripBefore(int $value): bool
    {
        return $value === self::COMMA || $value === self::FULL_STOP || $value === self::SEMICOLON ||
            $value === self::COLON || $value === self::EXCLAMATION || $value === self::QUESTION;
    }

    private static function isDotlike(int $value): bool
    {
        return $value === self::FULL_STOP || $value === self::ELLIPSIS;
    }

    private static function isOpenBracket(int $value): bool
    {
        return $value === self::PAREN_OPEN || $value === self::SQUARE_OPEN || $value === self::CURLY_OPEN;
    }

    private static function isCloseBracket(int $value): bool
    {
        return $value === self::PAREN_CLOSE || $value === self::SQUARE_CLOSE || $value === self::CURLY_CLOSE;
    }

    private static function matchingCloser(int $open): int
    {
        if ($open === self::PAREN_OPEN) {
            return self::PAREN_CLOSE;
        }
        if ($open === self::SQUARE_OPEN) {
            return self::SQUARE_CLOSE;
        }

        return self::CURLY_CLOSE;
    }

    /**
     * spaces.md 3.3: "- [ ] item" must not become "- [] item". The run may still collapse to
     * length 1.
     */
    private static function isEmptyBracketGuarded(int $left, int $right): bool
    {
        return self::isOpenBracket($left) && $right === self::matchingCloser($left);
    }

    /**
     * spaces.md 3.4. A run of dots is a different token from a terminal full stop -- a relative
     * path, a truncation, a typed ellipsis -- and deleting the space before it merges the run
     * with a preceding abbreviation dot ("See ../docs" -> "See../docs").
     *
     * $e indexes $right in the input array, and the run is measured there. Measuring it after
     * any edit had been applied would break the Chicago spaced ellipsis "Hello . . .", where
     * every dot is a lone dot at decision time and all three spaces must still strip.
     *
     * The word-start clause (spec 1.2.0): a single dot followed directly by a letter or an ASCII
     * digit starts a token -- ".NET", ".gitignore", ".5" -- so the space before it survives
     * ("Use .NET" no longer becomes "Use.NET"). A span boundary marker after the dot is neither,
     * so it still strips.
     */
    private static function isLoneDot(array $cp, int $e): bool
    {
        if (self::at($cp, $e) !== self::FULL_STOP) {
            return true;
        }

        $next = self::at($cp, $e + 1);
        if (UnicodeUtil::isLetter($next) || self::isDigitAscii($next)) {
            return false;
        }

        return !self::isDotlike($next);
    }

    private static function isDigitAscii(int $value): bool
    {
        return $value >= self::DIGIT_ZERO && $value <= self::DIGIT_NINE;
    }

    /** spaces.md 3.6: the recognised "mouth" glyphs of a Western text emoticon. */
    private static function isEmoticonMouth(int $value): bool
    {
        return $value === self::PAREN_OPEN || $value === self::PAREN_CLOSE ||
            $value === self::SQUARE_OPEN || $value === self::SQUARE_CLOSE ||
            $value === self::LETTER_D_UPPER || $value === self::LETTER_D_LOWER ||
            $value === self::LETTER_P_UPPER || $value === self::LETTER_P_LOWER ||
            $value === self::LETTER_O_UPPER || $value === self::LETTER_O_LOWER ||
            $value === self::SOLIDUS || $value === self::REVERSE_SOLIDUS ||
            $value === self::VERTICAL_LINE || $value === self::ASTERISK;
    }

    /**
     * spaces.md 3.6, the emoticon guard's eye side. A colon or semicolon immediately followed by
     * an optional "nose" and a recognised "mouth" is the eye of a Western text emoticon
     * (":-)", ":)", ";-)"), not sentence punctuation, and the space in front of it must survive.
     *
     * The mouth must not itself run into a letter or an ASCII digit -- ":Deal" is a colon before
     * a capitalised word, not a face -- which is the one check needed to keep this from firing
     * on ordinary prose. $e indexes $right, exactly as isLoneDot() does.
     */
    private static function isEmoticonEyeSideFires(array $cp, int $e): bool
    {
        $eye = self::at($cp, $e);
        if ($eye !== self::COLON && $eye !== self::SEMICOLON) {
            return false;
        }

        $i = $e + 1;
        $nose = self::at($cp, $i);
        if ($nose === self::HYPHEN_MINUS || $nose === self::CARET) {
            $i++;
        }

        if (!self::isEmoticonMouth(self::at($cp, $i))) {
            return false;
        }
        $i++;

        $after = self::at($cp, $i);

        return !UnicodeUtil::isLetter($after) && !self::isDigitAscii($after);
    }

    /**
     * spaces.md 3.6, the mouth side. "(" and "[" are EMOTICON-MOUTH members and OPEN-BRACKET
     * members at once, so without this the opening-bracket clause deleted the space after an
     * emoticon the eye side had just recognised: "a :( b" became "a :(b", and then "a:(b" on a
     * second pass, because a letter after the mouth stops the eye side firing.
     *
     * The walk mirrors the eye side's, backwards from $s, and needs no trailing check: the code
     * point after the mouth is the space run itself, which is neither a letter nor a digit. A
     * nose with no eye behind it is not a face -- EMOTICON-NOSE and EMOTICON-EYE are disjoint,
     * so the walk cannot mistake one for the other.
     */
    private static function isEmoticonMouthSideFires(array $cp, int $s): bool
    {
        if (!self::isEmoticonMouth(self::at($cp, $s - 1))) {
            return false;
        }

        $j = $s - 2;
        $nose = self::at($cp, $j);
        if ($nose === self::HYPHEN_MINUS || $nose === self::CARET) {
            $j--;
        }

        $eye = self::at($cp, $j);

        return $eye === self::COLON || $eye === self::SEMICOLON;
    }

    /**
     * spaces.md 3.2 step 5: the replacement length is a pure function of the two bounding code
     * points, computed once. A two-pass "collapse then strip" formulation would need a
     * fixed-point loop, which two runtimes would iterate differently.
     */
    private static function replacementLength(array $cp, int $s, int $e, int $left, int $right): int
    {
        // The guard is a clause of this decision, not a "skip the run" branch: "(  )" collapses
        // to "( )" (spaces.md 3.3, normative reading).
        if (self::isEmptyBracketGuarded($left, $right)) {
            return 1;
        }
        if (self::isOpenBracket($left) && !self::isEmoticonMouthSideFires($cp, $s)) {
            return 0;
        }
        if (self::isCloseBracket($right)) {
            return 0;
        }
        if (self::isStripBefore($right) && self::isLoneDot($cp, $e) && !self::isEmoticonEyeSideFires($cp, $e)) {
            return 0;
        }

        return 1;
    }

    /**
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
            if (self::at($cp, $i) !== self::SPACE) {
                $i++;
                continue;
            }

            $s = $i;
            $e = $s;
            while ($e < $n && self::at($cp, $e) === self::SPACE) {
                $e++;
            }
            $k = $e - $s;

            $left = self::at($cp, $s - 1);
            $right = self::at($cp, $e);

            // Boundary guard (3.2 step 4): indentation, Markdown hard breaks and text-unit edges
            // are structural. A span boundary marker counts as NONE here -- the one place in the
            // whole spec where a marker is not opaque content, per modes.md 3.3.
            if (
                $left === Sentinels::NONE || Sentinels::isMarker($left) || self::isBreak($left) ||
                $right === Sentinels::NONE || Sentinels::isMarker($right) || self::isBreak($right)
            ) {
                $i = $e;
                continue;
            }

            $length = self::replacementLength($cp, $s, $e, $left, $right);
            if ($length !== $k) {
                $replacement = $length === 0 ? [] : [self::SPACE];
                $edits[] = new Edit($s, $e, $replacement, 'spaces');
            }
            $i = $e;
        }

        return $edits;
    }
}
