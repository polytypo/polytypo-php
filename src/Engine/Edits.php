<?php

declare(strict_types=1);

namespace Polytypo\Engine;

use Polytypo\PolytypoException;

/**
 * Enforces the rule contract rather than trusting it (rules are community-contributed): edits
 * must be in bounds, ascending, non-overlapping, and made of real code points.
 */
final class Edits
{
    private function __construct()
    {
    }

    /** @param Edit[] $edits */
    public static function validateEdits(array $edits, int $length, ?string $ruleId = null): void
    {
        $previousEnd = 0;
        foreach ($edits as $i => $edit) {
            $where = $ruleId === null ? "edit {$i}" : "rule \"{$ruleId}\" edit {$i}";

            if ($edit->start < 0 || $edit->end > $length) {
                self::reject("{$where} is out of bounds ({$edit->start}, {$edit->end}) for length {$length}");
            }
            if ($edit->end < $edit->start) {
                self::reject("{$where} has end {$edit->end} before start {$edit->start}");
            }
            if ($edit->start < $previousEnd) {
                self::reject("{$where} starts at {$edit->start}, which overlaps or precedes the previous edit");
            }
            if ($ruleId !== null && $edit->ruleId !== $ruleId) {
                self::reject("{$where} is tagged \"{$edit->ruleId}\" but was produced by rule \"{$ruleId}\"");
            }
            foreach ($edit->replacement as $value) {
                if (!Codepoints::isValidCodepoint($value)) {
                    self::reject("{$where} contains an invalid code point ({$value})");
                }
            }
            $previousEnd = $edit->end;
        }
    }

    /**
     * Applies validated edits left to right, producing a new code-point array.
     *
     * @param int[] $cp
     * @param Edit[] $edits
     * @return int[]
     */
    public static function applyEdits(array $cp, array $edits, ?string $ruleId = null): array
    {
        self::validateEdits($edits, count($cp), $ruleId);
        if ($edits === []) {
            return $cp;
        }

        $out = [];
        $cursor = 0;
        foreach ($edits as $edit) {
            for ($i = $cursor; $i < $edit->start; $i++) {
                $out[] = $cp[$i];
            }
            foreach ($edit->replacement as $value) {
                $out[] = $value;
            }
            $cursor = $edit->end;
        }
        for ($i = $cursor; $i < count($cp); $i++) {
            $out[] = $cp[$i];
        }

        return $out;
    }

    private static function reject(string $message): never
    {
        throw new PolytypoException(PolytypoException::CODE_RULE_CONTRACT, $message);
    }
}
