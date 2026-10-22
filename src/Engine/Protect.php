<?php

declare(strict_types=1);

namespace Polytypo\Engine;

use Polytypo\Change;
use Polytypo\Modes\Runner;
use Polytypo\Modes\Spans;
use Polytypo\PolytypoException;

/**
 * The `protect` option (modes.md 3.9, spec 1.13.0): ranges of a "text" input, in code points,
 * that the caller marks as not text. Each maximal protected run becomes one boundary marker in
 * the code-point array -- Model C, the same machinery a skipped region gets in "html" -- and is
 * re-emitted byte for byte at its position.
 */
final class Protect
{
    private function __construct()
    {
    }

    private static function invalid(string $message): never
    {
        throw new PolytypoException(PolytypoException::CODE_INVALID_OPTION, $message);
    }

    /**
     * Validates $protect against the input's length in code points and merges touching ranges
     * into maximal runs. Null and an empty list are the same call.
     *
     * @param mixed $protect
     * @return list<array{0: int, 1: int}> maximal runs as [start, end)
     */
    public static function resolve(mixed $protect, int $length): array
    {
        if ($protect === null) {
            return [];
        }
        if (!is_array($protect) || !array_is_list($protect)) {
            self::invalid('protect must be a list of ranges with integer start and end');
        }

        $holes = [];
        $previousEnd = 0;
        foreach ($protect as $range) {
            if (
                !is_array($range)
                || !isset($range['start'], $range['end'])
                || !is_int($range['start'])
                || !is_int($range['end'])
            ) {
                self::invalid('protect entries must be arrays with integer start and end');
            }
            $start = $range['start'];
            $end = $range['end'];
            if ($start < 0 || $end <= $start) {
                self::invalid("protect range [{$start}, {$end}) is empty or negative");
            }
            if ($start < $previousEnd) {
                self::invalid("protect range [{$start}, {$end}) overlaps or precedes the previous one");
            }
            if ($end > $length) {
                self::invalid("protect range [{$start}, {$end}) runs past the end of the input ({$length})");
            }
            $last = count($holes) - 1;
            if ($last >= 0 && $holes[$last][1] === $start) {
                $holes[$last][1] = $end;
            } else {
                $holes[] = [$start, $end];
            }
            $previousEnd = $end;
        }

        return $holes;
    }

    /** Outside "text" a non-empty protect is refused, not ignored (modes.md 3.9.2). */
    public static function assertNone(mixed $protect, string $mode): void
    {
        if ($protect === null || $protect === []) {
            return;
        }
        self::invalid("protect is only available in text mode, not {$mode}");
    }

    /**
     * [S0] m S1 m ... [Sk]: a marker for every run, edges included, and the origin map to match.
     *
     * @param int[] $cp
     * @param list<array{0: int, 1: int}> $holes
     * @return array{0: int[], 1: int[]}
     */
    private static function layout(array $cp, array $holes): array
    {
        $array = [];
        $origin = [];
        $cursor = 0;
        foreach ($holes as [$start, $end]) {
            for ($i = $cursor; $i < $start; $i++) {
                $array[] = $cp[$i];
                $origin[] = $i;
            }
            $isLine = false;
            for ($i = $start; $i < $end; $i++) {
                if (Spans::isLineTerminator($cp[$i])) {
                    $isLine = true;
                    break;
                }
            }
            $array[] = $isLine ? Sentinels::LINE_MARKER : Sentinels::MARKER;
            $origin[] = Origin::NO_ORIGIN;
            $cursor = $end;
        }
        for ($i = $cursor, $n = count($cp); $i < $n; $i++) {
            $array[] = $cp[$i];
            $origin[] = $i;
        }

        return [$array, $origin];
    }

    /**
     * Returns the output code points and, for tests, the runs' positions in them.
     *
     * @param int[] $cp
     * @param list<array{0: int, 1: int}> $holes
     * @param string[] $plan
     * @param array<string, mixed> $localeData
     * @return array{0: int[], 1: list<array{start: int, end: int}>}
     */
    public static function runMapped(array $cp, array $holes, array $plan, array $localeData, RuleContext $ctx): array
    {
        [$array] = self::layout($cp, $holes);
        $out = Runner::runRulesOverSpans($array, $plan, $localeData, $ctx);
        $result = [];
        $mapped = [];
        $next = 0;
        foreach ($out as $value) {
            if (!Sentinels::isMarker($value)) {
                $result[] = $value;
                continue;
            }
            if (!isset($holes[$next])) {
                throw new PolytypoException(
                    PolytypoException::CODE_RULE_CONTRACT,
                    'boundary markers did not survive the pipeline',
                );
            }
            [$start, $end] = $holes[$next];
            $position = count($result);
            for ($i = $start; $i < $end; $i++) {
                $result[] = $cp[$i];
            }
            $mapped[] = ['start' => $position, 'end' => count($result)];
            $next++;
        }
        if ($next !== count($holes)) {
            throw new PolytypoException(
                PolytypoException::CODE_RULE_CONTRACT,
                'boundary markers did not survive the pipeline',
            );
        }

        return [$result, $mapped];
    }

    /**
     * @param int[] $cp
     * @param list<array{0: int, 1: int}> $holes
     * @param string[] $plan
     * @param array<string, mixed> $localeData
     * @return int[]
     */
    public static function run(array $cp, array $holes, array $plan, array $localeData, RuleContext $ctx): array
    {
        return self::runMapped($cp, $holes, $plan, $localeData, $ctx)[0];
    }

    /**
     * @param int[] $cp
     * @param list<array{0: int, 1: int}> $holes
     * @param string[] $plan
     * @param array<string, mixed> $localeData
     * @return Change[]
     */
    public static function analyze(array $cp, array $holes, array $plan, array $localeData, RuleContext $ctx): array
    {
        [$array, $origin] = self::layout($cp, $holes);

        return Pipeline::runRulesRecording(
            $array,
            $plan,
            $localeData,
            $ctx,
            $origin,
            count($cp),
            static fn (array $current, array $edits): array => Spans::filterBoundaryEdits(
                $current,
                $edits,
                Spans::spanRangesOf($current),
            ),
        );
    }
}
