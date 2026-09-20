<?php

declare(strict_types=1);

namespace Polytypo\Engine;

/** Per-call context every rule reads, alongside the code-point array and the locale data. */
final class RuleContext
{
    public function __construct(
        public readonly string $mode,
        public readonly ?string $dialect,
        public readonly string $locale,
        /**
         * nbsp.md 3.1a NARROW-TARGET, already resolved to a code point: U+202F by default,
         * U+00A0 when the caller passed narrowNbsp "nbsp". A rule reads a code point and never
         * the option, so the string never reaches the pipeline.
         */
        public readonly int $narrowTarget = 0x202F,
    ) {
    }
}
