<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Edit;
use Polytypo\Engine\RuleContext;
use Polytypo\Engine\UnicodeUtil;

/**
 * `ranges` -- spec/rules/ranges.md, order 25. Explicit opt-in: off by default
 * (spec/rules/order.json's "default": "off" for this rule id; the registry, not this file, is
 * what enforces that). Split out of `dashes` (spec 0.5.0) -- see ranges.md 1 and dashes.md 7.11
 * for why this is opt-in rather than a bounded structural fix: separating a genuine numeric
 * range (`5-10`) from a compound label sharing the identical shape (`Figure 5-10`) needs the
 * preceding word, which is exactly the open-ended, per-locale context this project's rules are
 * built never to consult.
 *
 * This class's own behaviour does not depend on whether the rule is enabled -- enable/disable is
 * the caller's concern (the registry only invokes a rule's scan function when it is active).
 *
 * Explicit index-based scanning only: no regex anywhere, and every index addresses the
 * code-point array, never a native string (ARCHITECTURE.md 4.1, 4.2).
 */
final class RangesRule
{
    private const SOLIDUS = 0x2F;

    private function __construct()
    {
    }

    /**
     * ranges.md 3.3 G5: equal-length ASCII digit runs compare lexicographically, so no integer
     * arithmetic (and no locale-dependent parsing) is needed.
     *
     * @param int[] $cp
     */
    private static function isNonDecreasing(array $cp, int $leftStart, int $rightStart, int $length): bool
    {
        for ($i = 0; $i < $length; $i++) {
            $l = $cp[$leftStart + $i];
            $r = $cp[$rightStart + $i];
            if ($l < $r) {
                return true;
            }
            if ($l > $r) {
                return false;
            }
        }

        return true;
    }

    /**
     * ranges.md 3.3, G1-G5. left/right are the token's post-joiner-walk flank indices (both
     * already known to be DIGIT by the caller).
     *
     * @param int[] $cp
     */
    private static function guardsPass(array $cp, int $left, int $right): bool
    {
        $n = count($cp);
        $a = $left;
        while ($a > 0 && DashShared::isDigit($cp[$a - 1])) {
            $a--;
        }
        $b = $right;
        while ($b + 1 < $n && DashShared::isDigit($cp[$b + 1])) {
            $b++;
        }

        $before = DashShared::effectiveNeighbour($cp, $a - 1, -1);
        $after = DashShared::effectiveNeighbour($cp, $b + 1, 1);

        // G1 -- no letter adjacency.
        if (UnicodeUtil::isLetter($before)) {
            return false;
        }
        // G2 -- no chain: an ISO date, an ISBN or a phone number always trips this. This guard
        // reads the input as it stood before this rule (or `dashes`) made any edit in this
        // pipeline pass -- ranges.md 4's own reasoning for why `ranges` must run before `dashes`.
        if (DashShared::isDashUnion($before)) {
            return false;
        }
        if (DashShared::isDashUnion($after)) {
            return false;
        }
        // G3 -- not part of a decimal or a path.
        if ($before === DashShared::FULL_STOP || $before === DashShared::COMMA || $before === self::SOLIDUS) {
            return false;
        }
        if ($after === self::SOLIDUS) {
            return false;
        }
        // G4 -- run lengths: equal, or the directional (1,2) branch with no leading zero on Rrun.
        $leftLength = $left - $a + 1;
        $rightLength = $b - $right + 1;
        if ($leftLength === 1 && $rightLength === 2 && $cp[$right] !== DashShared::DIGIT_ZERO) {
            return true;
        }
        if ($leftLength !== $rightLength) {
            return false;
        }

        // G5 -- non-decreasing (sound only because G4 guarantees equal length in this branch).
        return self::isNonDecreasing($cp, $a, $right, $leftLength);
    }

    /**
     * @param int[] $cp
     * @param array<string, mixed> $localeData
     * @return Edit[]
     */
    public static function scan(array $cp, array $localeData, RuleContext $ctx): array
    {
        $edits = [];
        $style = $localeData['dash']['range'];

        foreach (DashShared::findTokens($cp) as $token) {
            // ranges.md 3.2 -- a range candidate iff both flanks are DIGIT. `ranges` never
            // processes any other token shape; that is `dashes`' territory, and `dashes`
            // declines a digit-flanked token unconditionally too (operator decision, spec
            // 0.5.0) -- neither rule reinterprets the other's shape, whether or not `ranges` is
            // enabled.
            if (!DashShared::isDigit($token['leftCp']) || !DashShared::isDigit($token['rightCp'])) {
                continue;
            }

            if (!self::guardsPass($cp, $token['left'], $token['right'])) {
                continue;
            }

            // "none": the locale has no verified range convention, so nothing is substituted --
            // not a fallback to dash.parenthetical, nothing (ranges.md 2).
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

            // ranges.md 3.3.1: never make an edit whose entire content is invisible. Try the
            // unbound replacement first; only add the joiner pair if the dash itself is
            // genuinely changing.
            $unbound = DashShared::buildReplacement($style, false);
            $onlyBindingWouldChange = !DashShared::isSpacedStyle($style) &&
                DashShared::sameContent($cp, $token['spanStart'], $token['spanEnd'], $unbound);
            $bind = !DashShared::isSpacedStyle($style) && !$onlyBindingWouldChange;
            $replacement = $bind ? DashShared::buildReplacement($style, true) : $unbound;

            if (DashShared::sameContent($cp, $token['spanStart'], $token['spanEnd'], $replacement)) {
                continue;
            }

            $edits[] = new Edit($token['spanStart'], $token['spanEnd'], $replacement, 'ranges');
        }

        return $edits;
    }
}
