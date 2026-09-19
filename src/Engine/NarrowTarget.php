<?php

declare(strict_types=1);

namespace Polytypo\Engine;

use Polytypo\PolytypoException;

/**
 * nbsp.md 3.1a: resolve the `narrowNbsp` option to the code point the rule writes.
 *
 * Done once, at the call boundary, so no rule ever sees the option's string. Checked immediately
 * after `mode` and before `rules` -- the two checks that read nothing but the call itself come
 * first (ARCHITECTURE.md section 7). It runs whether or not `nbsp` is enabled, so a misspelled
 * value still throws rather than being silently ignored.
 */
final class NarrowTarget
{
    /** U+202F, the narrow no-break space -- the default NARROW-TARGET. */
    public const NARROW_NO_BREAK_SPACE = 0x202F;
    /** U+00A0, what `narrowNbsp` "nbsp" substitutes for it. */
    public const NO_BREAK_SPACE = 0x00A0;

    private function __construct()
    {
    }

    public static function resolve(?string $value): int
    {
        if ($value === null || $value === 'narrow') {
            return self::NARROW_NO_BREAK_SPACE;
        }
        if ($value === 'nbsp') {
            return self::NO_BREAK_SPACE;
        }

        throw new PolytypoException(
            PolytypoException::CODE_INVALID_OPTION,
            "Unknown narrowNbsp \"{$value}\". Expected \"narrow\" or \"nbsp\".",
        );
    }
}
