<?php

declare(strict_types=1);

namespace Polytypo\Engine;

/**
 * String <-> code-point array conversion (docs/ARCHITECTURE.md section 4.2: rules index an
 * explicit code-point array, never a native string). PHP strings are byte arrays, not
 * code-point-aware, so this conversion is not free the way it is in Python/Ruby -- every rule
 * below is written against "index i of the code-point array," never "index i of the string."
 */
final class Codepoints
{
    private function __construct()
    {
    }

    /** @return int[] */
    public static function toCodepoints(string $str): array
    {
        if ($str === '') {
            return [];
        }

        /** @var string[] $chars */
        $chars = mb_str_split($str, 1, 'UTF-8');

        return array_map(static fn (string $ch): int => mb_ord($ch, 'UTF-8'), $chars);
    }

    /** @param int[] $cp */
    public static function fromCodepoints(array $cp): string
    {
        return implode('', array_map(static fn (int $c): string => mb_chr($c, 'UTF-8'), $cp));
    }

    public static function isValidCodepoint(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 0x10FFFF;
    }
}
