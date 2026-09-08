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
    ) {
    }
}
