<?php

declare(strict_types=1);

namespace Polytypo\Modes;

/** A span's extent in the concatenated code-point array: s0/s1 of modes.md 3.4. */
final class SpanRange
{
    public function __construct(
        public readonly int $first,
        public readonly int $last,
    ) {
    }
}
