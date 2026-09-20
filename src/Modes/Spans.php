<?php

declare(strict_types=1);

namespace Polytypo\Modes;

use Polytypo\Engine\Edit;
use Polytypo\Engine\Origin;
use Polytypo\Engine\Sentinels;
use Polytypo\PolytypoException;

/**
 * The span model shared by every mode adapter that is not "text" (spec/rules/modes.md 3.2-3.5).
 * Mirrors the other four ports' spans.* exactly: markers are written here (mode layer),
 * classified in Engine\Sentinels and in each rule's own class definitions (L1).
 */
final class Spans
{
    private const LINE_TERMINATORS = [0x0A, 0x0D, 0x0B, 0x0C, 0x85, 0x2028, 0x2029];

    /** U+0020, the one emitted code point whose meaning is positional (modes.md 3.4, 5 item 2). */
    private const SPACE = 0x20;

    private function __construct()
    {
    }

    private static function gapIsLineBoundary(array $cp, int $from, int $to): bool
    {
        for ($i = $from; $i < $to; $i++) {
            if (in_array($cp[$i], self::LINE_TERMINATORS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sorts, drops empties, and coalesces spans separated by nothing in the source (modes.md
     * 7.5). Overlapping spans are an extractor bug and are rejected rather than silently merged.
     *
     * @param Span[] $spans
     * @return Span[]
     */
    public static function normalizeSpans(array $spans): array
    {
        $sorted = array_values(array_filter($spans, static fn (Span $s): bool => $s->end > $s->start));
        usort($sorted, static fn (Span $a, Span $b): int => $a->start <=> $b->start);

        $out = [];
        foreach ($sorted as $span) {
            if ($out === []) {
                $out[] = $span;
                continue;
            }
            $last = $out[count($out) - 1];
            if ($span->start < $last->end) {
                throw new PolytypoException(
                    PolytypoException::CODE_RULE_CONTRACT,
                    "mode extractor produced overlapping spans ({$last->start}, {$last->end}) and "
                        . "({$span->start}, {$span->end})",
                );
            }
            if ($span->start === $last->end) {
                $out[count($out) - 1] = new Span($last->start, $span->end);
            } else {
                $out[] = $span;
            }
        }

        return $out;
    }

    /**
     * S1 (marker) S2 ... Sm (modes.md 3.5 step 2), given the source's full code-point array.
     *
     * @param int[] $sourceCp
     * @param Span[] $spans
     * @return int[]
     */
    public static function concatenateSpans(array $sourceCp, array $spans): array
    {
        $cp = [];
        $previous = null;
        foreach ($spans as $span) {
            if ($previous !== null) {
                $cp[] = self::gapIsLineBoundary($sourceCp, $previous->end, $span->start)
                    ? Sentinels::LINE_MARKER
                    : Sentinels::MARKER;
            }
            for ($i = $span->start; $i < $span->end; $i++) {
                $cp[] = $sourceCp[$i];
            }
            $previous = $span;
        }

        return $cp;
    }

    /**
     * The origin map for concatenateSpans (analyze.md section 2): for every code point of the
     * joined array, the code-point offset of the character it came from IN THE DOCUMENT, and
     * Origin::NO_ORIGIN for the markers, which came from nowhere. A Span's bounds are already
     * code-point offsets (the mode adapter converts its parser's byte offsets away before
     * constructing one), so no coordinate conversion belongs here.
     *
     * @param Span[] $spans
     * @return int[]
     */
    public static function originOfSpans(array $spans): array
    {
        $origin = [];
        $first = true;
        foreach ($spans as $span) {
            if (!$first) {
                $origin[] = Origin::NO_ORIGIN;
            }
            for ($i = $span->start; $i < $span->end; $i++) {
                $origin[] = $i;
            }
            $first = false;
        }

        return $origin;
    }

    /**
     * The span extents of the array as it stands. Recomputed after every rule, because applying
     * edits shifts every index after the first one -- the markers themselves always survive,
     * since no edit may contain one.
     *
     * @param int[] $cp
     * @return SpanRange[]
     */
    public static function spanRangesOf(array $cp): array
    {
        $ranges = [];
        $first = 0;
        foreach ($cp as $i => $value) {
            if (Sentinels::isMarker($value)) {
                $ranges[] = new SpanRange($first, $i - 1);
                $first = $i + 1;
            }
        }
        $ranges[] = new SpanRange($first, count($cp) - 1);

        return $ranges;
    }

    /** @param SpanRange[] $ranges */
    private static function spanContaining(array $ranges, int $p): ?SpanRange
    {
        foreach ($ranges as $r) {
            if ($r->first <= $p && $p <= $r->last + 1) {
                return $r;
            }
        }

        return null;
    }

    /**
     * modes.md 3.4, two safety nets, both pure functions of (p, q, r, s0, s1):
     *
     *  1. No edit may contain a marker -- one that does is a bug, discarded rather than
     *     redistributed.
     *  2. The edge-growth rule: an edit is discarded if it would place code points at an
     *     extremity of its span that were not there before.
     *
     * @param int[] $cp
     * @param Edit[] $edits
     * @param SpanRange[] $ranges
     * @return Edit[]
     */
    public static function filterBoundaryEdits(array $cp, array $edits, array $ranges): array
    {
        return array_values(array_filter($edits, static function (Edit $edit) use ($cp, $ranges): bool {
            for ($i = $edit->start; $i < $edit->end; $i++) {
                if (Sentinels::isMarker($cp[$i])) {
                    return false;
                }
            }

            $p = $edit->start;
            $q = $edit->end - 1;
            $d = $edit->end - $edit->start;
            $r = count($edit->replacement);
            $span = self::spanContaining($ranges, $p);
            if ($span === null || ($p !== $span->first && $q !== $span->last)) {
                return true;
            }
            if ($r > $d) {
                return false;
            }
            if ($r > 0 && $p === $span->first && $edit->replacement[0] === self::SPACE && $cp[$p] !== self::SPACE) {
                return false;
            }
            if ($r > 0 && $q === $span->last && $edit->replacement[$r - 1] === self::SPACE && $cp[$q] !== self::SPACE) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Redistributes the transformed array back to one piece per span (modes.md 3.5 step 4).
     *
     * @param int[] $cp
     * @return int[][]
     */
    public static function splitOnMarker(array $cp, int $expected): array
    {
        $pieces = [[]];
        foreach ($cp as $value) {
            if (Sentinels::isMarker($value)) {
                $pieces[] = [];
            } else {
                $pieces[count($pieces) - 1][] = $value;
            }
        }
        if (count($pieces) !== $expected) {
            throw new PolytypoException(
                PolytypoException::CODE_RULE_CONTRACT,
                'boundary markers did not survive the pipeline: expected ' . $expected
                    . ' spans, found ' . count($pieces),
            );
        }

        return $pieces;
    }
}
