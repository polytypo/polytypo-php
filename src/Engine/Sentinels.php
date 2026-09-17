<?php

declare(strict_types=1);

namespace Polytypo\Engine;

/**
 * The three non-code-point values a rule can meet in the array it scans. They live together and
 * must stay pairwise disjoint (mirrors the JS/Python/Go/Ruby reference implementations' own
 * sentinels module):
 *
 *   - NONE — there is nothing at that index; the array ends here.
 *   - MARKER — a span boundary whose skipped region has no line terminator. Per modes.md 3.3 it
 *     is opaque content everywhere except the OPENISH/CLOSEISH classes: a member of both in
 *     `quotes` and `apostrophe`, of CLOSEISH only in `nbsp` (spec 1.2.0).
 *   - LINE_MARKER — a span boundary whose skipped region contains a line terminator. A member of
 *     BREAK for every rule, everywhere.
 */
final class Sentinels
{
    public const MARKER = -1;
    public const LINE_MARKER = -2;
    public const NONE = -3;

    private function __construct()
    {
    }

    /** True for either span boundary. NONE is deliberately not a marker: it is not in the array. */
    public static function isMarker(int $value): bool
    {
        return $value === self::MARKER || $value === self::LINE_MARKER;
    }
}
