<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Edit;
use Polytypo\Engine\Sentinels;
use Polytypo\Engine\UnicodeUtil;

/**
 * `dash.parenthetical = "em-paired"` -- spec/rules/dashes.md 3.4a. The pair is written with a
 * space outside it and none inside (`Llegó —por fin— a casa`), which no per-token rule can
 * express: which stroke opens and which closes is a property of the sentence, not of the token.
 * A sentence (segment) is converted only when it holds exactly two counted strokes and both are
 * eligible; anything else is left as typed.
 *
 * Explicit index-based scanning only: no regex, every index addresses the code-point array.
 */
final class DashesPaired
{
    private const FULL_STOP = 0x2E;
    private const QUESTION = 0x3F;
    private const EXCLAMATION = 0x21;
    private const ELLIPSIS = 0x2026;
    private const PLUS = 0x2B;
    private const SOLIDUS = 0x2F;

    private function __construct()
    {
    }

    private static function isTerminal(int $cp): bool
    {
        return $cp === self::FULL_STOP || $cp === self::QUESTION || $cp === self::EXCLAMATION ||
            $cp === self::ELLIPSIS;
    }

    /**
     * A terminal ends a segment only before a space (U+0020 or a no-break space, which `nbsp`
     * puts there later), a line break, a marker or the end of input.
     *
     * @param int[] $cp
     */
    private static function endsSegment(array $cp, int $i): bool
    {
        if (!self::isTerminal($cp[$i])) {
            return false;
        }
        if ($i + 1 >= count($cp)) {
            return true;
        }
        $next = $cp[$i + 1];

        return $next === DashShared::SPACE || DashShared::isNoBreakSpace($next) ||
            DashShared::isBreak($next) || Sentinels::isMarker($next);
    }

    /**
     * @param int[] $cp
     * @return array{s: int, e: int, lsp: int, rsp: int, left: int, right: int, eligible: bool}|null
     */
    private static function classify(array $cp, int $s, int $e): ?array
    {
        $n = count($cp);
        $k = $e - $s;
        if ($k > 3) {
            return null;
        }

        $lsp = ($s > 0 && $cp[$s - 1] === DashShared::SPACE) ? 1 : 0;
        $rsp = ($e < $n && $cp[$e] === DashShared::SPACE) ? 1 : 0;
        $first = $cp[$s];
        $isHyphenShaped = $first === DashShared::HYPHEN_MINUS || $first === DashShared::HYPHEN ||
            $first === DashShared::MINUS_SIGN;
        // A hyphen inside a word is a compound, not a stroke.
        if ($k === 1 && $isHyphenShaped && $lsp === 0 && $rsp === 0) {
            return null;
        }
        // The hyphen of `+/-`, which `symbols` (order 80) turns into `±`: counting it would let
        // that later rule change the number of strokes in the segment.
        if (
            $k === 1 && $first === DashShared::HYPHEN_MINUS && $s >= 2 &&
            $cp[$s - 1] === self::SOLIDUS && $cp[$s - 2] === self::PLUS
        ) {
            return null;
        }

        $counted = static fn (bool $eligible, int $left, int $right): array => [
            's' => $s, 'e' => $e, 'lsp' => $lsp, 'rsp' => $rsp,
            'left' => $left, 'right' => $right, 'eligible' => $eligible,
        ];

        $left = $s - 1 - $lsp;
        $right = $e + $rsp;
        if ($left < 0 || $right >= $n) {
            return $counted(false, $left, $right);
        }

        $joinStart = $left + 1;
        $joinEnd = $right;
        while ($left >= 0 && $cp[$left] === DashShared::WORD_JOINER) {
            $left--;
        }
        while ($right < $n && $cp[$right] === DashShared::WORD_JOINER) {
            $right++;
        }
        if ($left < 0 || $right >= $n) {
            return $counted(false, $left, $right);
        }
        $crossedJoiner = $left + 1 !== $joinStart || $right !== $joinEnd;

        if (DashShared::rangeFlanks($cp, $left, $right) !== null) {
            return null;
        }
        if ($crossedJoiner) {
            return $counted(false, $left, $right);
        }

        $leftCp = $cp[$left];
        $rightCp = $cp[$right];
        if (DashShared::isBreak($leftCp) || DashShared::isBreak($rightCp)) {
            return $counted(false, $left, $right);
        }
        if (DashShared::isInertDash($leftCp) || DashShared::isInertDash($rightCp)) {
            return $counted(false, $left, $right);
        }
        if (DashShared::isDash($leftCp) || DashShared::isDash($rightCp)) {
            return $counted(false, $left, $right);
        }
        if ($leftCp === DashShared::SPACE || DashShared::isNoBreakSpace($leftCp)) {
            return $counted(false, $left, $right);
        }
        if ($rightCp === DashShared::SPACE || DashShared::isNoBreakSpace($rightCp)) {
            return $counted(false, $left, $right);
        }
        if (DashShared::isClusterInert($cp, $s, $e)) {
            return $counted(false, $left, $right);
        }

        // A stroke spaced on one side only is eligible solely as the one em dash that is already
        // half of the target form; `-mente`, `pre-`, `--force` and `- item` are not dashes.
        if ($lsp !== $rsp && !($k === 1 && $first === DashShared::EM_DASH)) {
            return $counted(false, $left, $right);
        }
        // P4: a tight stroke between two Roman numerals is a range already in its correct form.
        if ($lsp === 0 && $rsp === 0 && DashesRule::isRomanFlanked($cp, $left, $right)) {
            return $counted(false, $left, $right);
        }
        // P5: an authored lone en dash is declined in every locale.
        if ($k === 1 && $first === DashShared::EN_DASH) {
            return $counted(false, $left, $right);
        }

        return $counted(true, $left, $right);
    }

    private static function wantsSpace(int $cp): bool
    {
        return UnicodeUtil::isLetter($cp) || DashShared::isDigit($cp);
    }

    /**
     * @param int[] $cp
     * @return Edit[]
     */
    public static function scan(array $cp): array
    {
        $n = count($cp);
        $edits = [];
        $segment = [];
        $i = 0;

        while ($i < $n) {
            if (DashShared::isDash($cp[$i])) {
                $s = $i;
                $e = $s;
                while ($e < $n && DashShared::isDash($cp[$e])) {
                    $e++;
                }
                $i = $e;
                $run = self::classify($cp, $s, $e);
                if ($run !== null) {
                    $segment[] = $run;
                }
                continue;
            }
            if (self::endsSegment($cp, $i)) {
                self::flush($cp, $segment, $edits);
                $segment = [];
            }
            $i++;
        }
        self::flush($cp, $segment, $edits);

        return $edits;
    }

    /**
     * @param int[] $cp
     * @param array<int, array{s: int, e: int, lsp: int, rsp: int, left: int, right: int, eligible: bool}> $segment
     * @param Edit[] $edits
     */
    private static function flush(array $cp, array $segment, array &$edits): void
    {
        if (count($segment) === 2) {
            self::emitPair($cp, $segment[0], $segment[1], $edits);
        }
    }

    /**
     * @param int[] $cp
     * @param array{s: int, e: int, lsp: int, rsp: int, left: int, right: int, eligible: bool} $a
     * @param array{s: int, e: int, lsp: int, rsp: int, left: int, right: int, eligible: bool} $b
     * @param Edit[] $edits
     */
    private static function emitPair(array $cp, array $a, array $b, array &$edits): void
    {
        if (!$a['eligible'] || !$b['eligible']) {
            return;
        }
        if ($a['lsp'] === 0 && $a['rsp'] === 1) {
            return; // the first stroke is shaped like a closer
        }
        if ($b['lsp'] === 1 && $b['rsp'] === 0) {
            return; // the second stroke is shaped like an opener
        }
        for ($j = $a['e']; $j < $b['s']; $j++) {
            if (DashShared::isBreak($cp[$j])) {
                return;
            }
        }

        $insertBefore = $a['lsp'] === 0 && self::wantsSpace($cp[$a['left']]);
        $insertAfter = $b['rsp'] === 0 && self::wantsSpace($cp[$b['right']]);
        if ($insertBefore && DashShared::isSpacingTransitionBlockedLeft($cp, $a['left'])) {
            return;
        }
        if ($insertAfter && DashShared::isSpacingTransitionBlockedRight($cp, $b['right'])) {
            return;
        }

        $opener = ($a['lsp'] === 1 || $insertBefore)
            ? [DashShared::SPACE, DashShared::EM_DASH]
            : [DashShared::EM_DASH];
        $closer = ($b['rsp'] === 1 || $insertAfter)
            ? [DashShared::EM_DASH, DashShared::SPACE]
            : [DashShared::EM_DASH];

        foreach ([[$a, $opener], [$b, $closer]] as [$run, $replacement]) {
            $start = $run['s'] - $run['lsp'];
            $end = $run['e'] + $run['rsp'];
            if (DashShared::sameContent($cp, $start, $end, $replacement)) {
                continue;
            }
            $edits[] = new Edit($start, $end, $replacement, 'dashes');
        }
    }
}
