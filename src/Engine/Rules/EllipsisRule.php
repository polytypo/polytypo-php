<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Edit;
use Polytypo\Engine\RuleContext;
use Polytypo\Engine\Sentinels;

/**
 * spec/rules/ellipsis.md. No regex anywhere (ARCHITECTURE.md section 4.1): a single
 * left-to-right scan over the code-point array, deciding each maximal DOTLIKE run as a pure
 * function of the run itself and the one code point to its left.
 */
final class EllipsisRule
{
    private const DOT = 0x2E;
    private const ELL = 0x2026;
    private const EXCLAMATION = 0x21;
    private const QUESTION = 0x3F;

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

    private static function isDotlike(int $value): bool
    {
        return $value === self::DOT || $value === self::ELL;
    }

    private static function isTerminal(int $value): bool
    {
        return $value === self::EXCLAMATION || $value === self::QUESTION;
    }

    /**
     * Whether cp[s...e) is already exactly target, so a would-be no-op edit is never emitted.
     *
     * @param int[] $target
     */
    private static function isSameRun(array $cp, int $s, int $e, array $target): bool
    {
        if ($e - $s !== count($target)) {
            return false;
        }

        foreach ($target as $j => $want) {
            if (self::at($cp, $s + $j) !== $want) {
                return false;
            }
        }

        return true;
    }

    /**
     * ellipsis.md 3.3 steps 4-6. null means "emit nothing"; otherwise the final form of the
     * whole run.
     *
     * @return int[]|null
     */
    private static function runTarget(int $k, int $q, int $left, bool $abbreviated): ?array
    {
        if ($k === 2 && $q === 0) {
            // The two-dot run is unconditionally inert where "?.." is the correct output form;
            // that is what stops "?.." <-> "?…" from oscillating (ellipsis.md 5).
            if ($abbreviated) {
                return null;
            }
            if (self::isTerminal($left)) {
                return [self::ELL];
            }

            return null;
        }
        if ($k === 1 && $q === 0) {
            return null;
        }

        // k = 1 with an existing U+2026, or any run of 2+ containing one: normalise, then decide
        // the abbreviated form on the same span (ellipsis.md 3.3 step 6).
        if ($abbreviated && self::isTerminal($left)) {
            return [self::DOT, self::DOT];
        }

        return [self::ELL];
    }

    /**
     * @param int[] $cp
     * @param array<string, mixed> $localeData
     * @return Edit[]
     */
    public static function scan(array $cp, array $localeData, RuleContext $ctx): array
    {
        $n = count($cp);
        $abbreviated = $localeData['ellipsis']['abbreviatedAfterTerminal'];
        $edits = [];
        $i = 0;

        while ($i < $n) {
            if (!self::isDotlike(self::at($cp, $i))) {
                $i++;
                continue;
            }

            $s = $i;
            $e = $s;
            $q = 0;
            while ($e < $n && self::isDotlike(self::at($cp, $e))) {
                if (self::at($cp, $e) === self::ELL) {
                    $q++;
                }
                $e++;
            }
            $k = $e - $s;
            $left = self::at($cp, $s - 1);

            // One decision per run, one edit per run (ellipsis.md 3.3 step 6).
            $target = self::runTarget($k, $q, $left, $abbreviated);
            if ($target !== null && !self::isSameRun($cp, $s, $e, $target)) {
                $edits[] = new Edit($s, $e, $target, 'ellipsis');
            }
            $i = $e;
        }

        return $edits;
    }
}
