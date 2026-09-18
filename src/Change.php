<?php

declare(strict_types=1);

namespace Polytypo;

/**
 * One entry of Polytypo::analyze()'s result, in input coordinates (spec/rules/analyze.md
 * section 2): $ruleId is a rule id from spec/rules/order.json, $start/$end are code-point
 * offsets into the input (inclusive/exclusive), and $before/$after are the text on either side
 * of that one edit. $start === $end is a pure insertion, and $before is then empty.
 */
final class Change
{
    public function __construct(
        public readonly string $ruleId,
        public readonly int $start,
        public readonly int $end,
        public readonly string $before,
        public readonly string $after,
    ) {
    }
}
