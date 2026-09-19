<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Edit;
use Polytypo\Engine\RuleContext;
use Polytypo\Engine\UnicodeUtil;

/**
 * `dashes` -- spec/rules/dashes.md, order 30. Parenthetical-dash processing only, as of spec
 * 0.5.0: numeric/date-range recognition moved to the `ranges` rule (order 25, off by default),
 * which owns `dash.range` and shares this rule's token-scanning and guard machinery via
 * DashShared. See dashes.md 1 and 7.11, and ranges.md 1, for why the split happened and why
 * range detection is opt-in rather than fixed structurally.
 *
 * A digit-flanked dash token is declined here unconditionally -- never reinterpreted as a
 * parenthetical dash -- regardless of whether `ranges` is enabled (operator decision, spec
 * 0.5.0). That was already true of every prior spec version: the range/parenthetical branches
 * have always been mutually exclusive per token, on the same "both flanks DIGIT" test that now
 * decides which rule a token belongs to rather than which branch of one rule it takes.
 *
 * Explicit index-based scanning only: no regex anywhere, and every index addresses the
 * code-point array, never a native string (ARCHITECTURE.md 4.1, 4.2).
 */
final class DashesRule
{
    private const ROMAN_I = 0x49;
    private const ROMAN_V = 0x56;
    private const ROMAN_X = 0x58;
    private const ROMAN_L = 0x4C;
    private const ROMAN_C = 0x43;
    private const ROMAN_D = 0x44;
    private const ROMAN_M = 0x4D;

    private function __construct()
    {
    }

    /**
     * dashes.md 3.1 ROMAN: the seven uppercase Roman-numeral letters only. Lower-case forms are
     * not members -- see dashes.md 3.4 P4.
     */
    private static function isRoman(int $cp): bool
    {
        return $cp === self::ROMAN_I || $cp === self::ROMAN_V || $cp === self::ROMAN_X ||
            $cp === self::ROMAN_L || $cp === self::ROMAN_C || $cp === self::ROMAN_D || $cp === self::ROMAN_M;
    }

    /**
     * dashes.md 3.4 P4 -- the Roman-numeral veto. A tight dash between two word-bounded ROMAN
     * runs is a range already in its correct Russian form (`в XV—XVII веках`); `ranges` cannot
     * see it, because ranges.md 3.2 needs a DIGIT on each side, so without this the
     * parenthetical branch would space out input that was already right.
     *
     * A veto only: it never converts. Admitting ROMAN runs as range candidates would also fix
     * `XV-XVII`, but it fires on all-caps words built from the same letters (`MIX`, `CIVIL`), and
     * converting is the direction that damages -- see dashes.md 7.10.
     *
     * @param int[] $cp
     */
    private static function isRomanFlanked(array $cp, int $left, int $right): bool
    {
        $n = count($cp);

        if (!self::isRoman($cp[$left]) || !self::isRoman($cp[$right])) {
            return false;
        }

        $a = $left;
        while ($a > 0 && self::isRoman($cp[$a - 1])) {
            $a--;
        }
        if ($a > 0 && UnicodeUtil::isLetter($cp[$a - 1])) {
            return false;
        }

        $b = $right;
        while ($b + 1 < $n && self::isRoman($cp[$b + 1])) {
            $b++;
        }
        if ($b + 1 < $n && UnicodeUtil::isLetter($cp[$b + 1])) {
            return false;
        }

        return true;
    }

    /**
     * @param int[] $cp
     * @param array<string, mixed> $localeData
     * @return Edit[]
     */
    public static function scan(array $cp, array $localeData, RuleContext $ctx): array
    {
        $edits = [];
        $style = $localeData['dash']['parenthetical'];

        foreach (DashShared::findTokens($cp) as $token) {
            // A digit-flanked token is `ranges`' territory, never `dashes`' -- declined
            // unconditionally, whether or not `ranges` is enabled (operator decision, spec
            // 0.5.0).
            // A range candidate is `ranges`' territory, never `dashes`'. Since spec 1.3.0 a
            // candidate may carry a matched closed-up symbol on a flank (ranges.md 3.2a), which
            // is why this is rangeFlanks rather than a digit test on both flanks.
            if (DashShared::rangeFlanks($cp, $token['left'], $token['right']) !== null) {
                continue;
            }

            // dashes.md 3.4 P5 -- authored en-dash mark-identity veto (spec 0.6.0). A run
            // consisting of exactly one U+2013 is declined unconditionally: every locale, tight
            // or spaced, regardless of dash.parenthetical's target glyph.
            if ($token['e'] - $token['s'] === 1 && $cp[$token['s']] === DashShared::EN_DASH) {
                continue;
            }

            // dashes.md 3.4 P1 -- a bare hyphen-shaped stroke must be spaced (the compound-word
            // guard): well-known, e-mail, Jean-Luc, well-being, and their U+2010/U+2212
            // spellings.
            if (
                $token['e'] - $token['s'] === 1 &&
                ($cp[$token['s']] === DashShared::HYPHEN_MINUS || $cp[$token['s']] === DashShared::HYPHEN ||
                    $cp[$token['s']] === DashShared::MINUS_SIGN) &&
                $token['lsp'] === 0
            ) {
                continue;
            }

            // dashes.md 3.4 P4 -- Roman-numeral veto.
            if (
                $token['lsp'] === 0 && $token['rsp'] === 0 &&
                self::isRomanFlanked($cp, $token['left'], $token['right'])
            ) {
                continue;
            }

            // "none": the locale has no verified convention, so nothing is substituted.
            if ($style === 'none') {
                continue;
            }

            if (DashShared::isSpacedStyle($style)) {
                // T1: a tight token may not become spaced across a digit run that has a far
                // dash.
                if (
                    $token['lsp'] === 0 && $token['rsp'] === 0 &&
                    DashShared::isSpacingTransitionBlocked($cp, $token['left'], $token['right'])
                ) {
                    continue;
                }
                // T2: the emitted U+0020 must not land where `spaces` (order 10) would delete
                // it.
                if (DashShared::isStripBeforeOrCloseBracket($token['rightCp'])) {
                    continue;
                }
                if (DashShared::isOpenBracket($token['leftCp'])) {
                    continue;
                }
            }

            // `dashes` never binds: an interrupting dash is exactly where a line may break
            // (dashes.md 3.3.1's binding is `ranges`-only). Every token this rule accepts has
            // crossedJoiner=false (DashShared::findTokens() never returns a crossed-joiner,
            // non-digit-flanked token), so the plain s-lsp/e+rsp span is always exactly the
            // token's own span here.
            $replacement = DashShared::buildReplacement($style, false);
            $spanStart = $token['s'] - $token['lsp'];
            $spanEnd = $token['e'] + $token['rsp'];
            if (DashShared::sameContent($cp, $spanStart, $spanEnd, $replacement)) {
                continue;
            }

            $edits[] = new Edit($spanStart, $spanEnd, $replacement, 'dashes');
        }

        return $edits;
    }
}
