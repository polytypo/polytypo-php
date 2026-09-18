<?php

declare(strict_types=1);

namespace Polytypo\Engine;

use Polytypo\Change;

/**
 * analyze.md section 2: every reported offset is a code-point offset into the input the caller
 * passed, in every mode. The rules, however, run over an array that is not the input -- in "text"
 * mode edits from earlier rules have already shifted it, and in "html" mode it is the
 * marker-joined concatenation of the processable spans (modes.md 3.5). This class carries the one
 * structure that bridges the two: an origin map, parallel to the current code-point array,
 * holding the input offset each code point came from, or NO_ORIGIN for one the pipeline itself
 * produced. Mirrors polytypo-js's src/engine/origin.ts.
 */
final class Origin
{
    public const NO_ORIGIN = -1;

    private function __construct()
    {
    }

    /**
     * The input offset an edit boundary at $index addresses. Synthetic code points have no origin
     * of their own, so the scan runs forward to the first that has one -- an insertion between two
     * earlier insertions still lands where the next real character is. Falling off the end means
     * the boundary is at the end of the input.
     *
     * @param int[] $origin
     */
    public static function originAt(array $origin, int $index, int $inputLength): int
    {
        $count = count($origin);
        for ($i = $index; $i < $count; $i++) {
            if ($origin[$i] !== self::NO_ORIGIN) {
                return $origin[$i];
            }
        }

        return $inputLength;
    }

    /**
     * The origin map for the array Edits::applyEdits is about to produce. A replacement of equal
     * length keeps its origins position by position, which is what makes a conversion
     * (U+0020 -> U+00A0) still point at the character it converted; anything longer is synthetic
     * beyond the positions it covers.
     *
     * @param int[] $origin
     * @param Edit[] $edits
     * @return int[]
     */
    public static function applyEditsToOrigin(array $origin, array $edits): array
    {
        if ($edits === []) {
            return $origin;
        }

        $out = [];
        $cursor = 0;
        foreach ($edits as $edit) {
            for ($i = $cursor; $i < $edit->start; $i++) {
                $out[] = $origin[$i];
            }
            $length = count($edit->replacement);
            for ($k = 0; $k < $length; $k++) {
                $source = $edit->start + $k;
                $out[] = $source < $edit->end ? $origin[$source] : self::NO_ORIGIN;
            }
            $cursor = $edit->end;
        }
        $total = count($origin);
        for ($i = $cursor; $i < $total; $i++) {
            $out[] = $origin[$i];
        }

        return $out;
    }

    /**
     * One rule's edits, in the coordinates that rule saw, rendered as Changes in input
     * coordinates. $before is the text this rule replaced and $after what it replaced it with
     * (analyze.md section 2), so on a text two rules have both touched, $before is what the second
     * rule saw rather than what the caller typed -- section 5 says so and shows the French case
     * where it matters.
     *
     * @param int[] $cp
     * @param Edit[] $edits
     * @param int[] $origin
     * @return Change[]
     */
    public static function recordChanges(
        array $cp,
        array $edits,
        array $origin,
        int $inputLength,
        string $ruleId,
    ): array {
        $changes = [];
        foreach ($edits as $edit) {
            $changes[] = new Change(
                ruleId: $ruleId,
                start: self::originAt($origin, $edit->start, $inputLength),
                end: self::originAt($origin, $edit->end, $inputLength),
                before: Codepoints::fromCodepoints(array_slice($cp, $edit->start, $edit->end - $edit->start)),
                after: Codepoints::fromCodepoints($edit->replacement),
            );
        }

        return $changes;
    }
}
