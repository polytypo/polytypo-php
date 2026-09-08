<?php

declare(strict_types=1);

namespace Polytypo\Modes;

/**
 * A processable span, identified by its offsets in the original source, addressed as
 * code-point indices (ARCHITECTURE.md section 4.2).
 */
final class Span
{
    public function __construct(
        public readonly int $start,
        public readonly int $end,
    ) {
    }
}
