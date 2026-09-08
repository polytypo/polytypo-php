<?php

declare(strict_types=1);

namespace Polytypo\Engine;

/**
 * One replacement a rule proposes: replace cp[start...end) with replacement. Indices address the
 * code-point array, never a native string (docs/ARCHITECTURE.md section 4.2).
 */
final class Edit
{
    /** @param int[] $replacement */
    public function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly array $replacement,
        public readonly string $ruleId,
    ) {
    }
}
