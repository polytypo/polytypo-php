<?php

declare(strict_types=1);

namespace Polytypo\Engine;

use Polytypo\PolytypoException;

/**
 * Locale resolution (spec/rules/locale-resolution.md) and locale data loading. Resolution is
 * specified centrally and index-based -- no regex, no host locale, no platform
 * locale-negotiation library (ARCHITECTURE.md sections 4.1, 4.4, 4.7). Data is embedded in the
 * installed package (resources/spec/), read once and memoized -- resolution itself stays a pure
 * function of (locale, registry), never mutated after first load.
 */
final class Locale
{
    private const HYPHEN = 0x2d;
    private const UNDERSCORE = 0x5f;
    private const UPPER_A = 0x41;
    private const UPPER_Z = 0x5a;
    private const LOWER_A = 0x61;
    private const LOWER_Z = 0x7a;
    private const CASE_GAP = 0x20;

    /** @var array<string, mixed>|null */
    private static ?array $registry = null;

    /** @var array<string, array<string, mixed>> */
    private static array $localeDataRaw = [];

    private function __construct()
    {
    }

    private static function dataDir(): string
    {
        return dirname(__DIR__, 2) . '/resources/spec';
    }

    /** @return array<string, mixed> */
    private static function readJsonFile(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new PolytypoException(
                PolytypoException::CODE_MALFORMED_LOCALE_DATA,
                "could not read spec data file \"{$path}\"",
            );
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new PolytypoException(
                PolytypoException::CODE_MALFORMED_LOCALE_DATA,
                "spec data file \"{$path}\" did not decode to a JSON object",
            );
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public static function registry(): array
    {
        if (self::$registry === null) {
            self::$registry = self::readJsonFile(self::dataDir() . '/locales/registry.json');
        }

        return self::$registry;
    }

    /** @return array<string, mixed> */
    public static function localeDataRaw(string $localeId): array
    {
        if (!isset(self::$localeDataRaw[$localeId])) {
            self::$localeDataRaw[$localeId] = self::readJsonFile(self::dataDir() . "/locales/{$localeId}.json");
        }

        return self::$localeDataRaw[$localeId];
    }

    private static function isLowerAscii(int $cp): bool
    {
        return $cp >= self::LOWER_A && $cp <= self::LOWER_Z;
    }

    private static function isUpperAscii(int $cp): bool
    {
        return $cp >= self::UPPER_A && $cp <= self::UPPER_Z;
    }

    /**
     * spec/rules/locale-resolution.md 3.2. ASCII arithmetic only: no mb_strtolower/mb_strtoupper,
     * no ICU, no host locale, so a Turkish process resolves "EN-us" exactly as a Finnish one does
     * (ARCHITECTURE.md 4.4).
     *
     * @return int[]
     */
    private static function canonicalize(string $tag): array
    {
        $c = array_map(
            static fn (int $cp): int => $cp === self::UNDERSCORE ? self::HYPHEN : $cp,
            Codepoints::toCodepoints($tag),
        );
        $m = count($c);
        if ($m >= 2) {
            foreach ([0, 1] as $j) {
                if (self::isUpperAscii($c[$j])) {
                    $c[$j] += self::CASE_GAP;
                }
            }
        }
        if ($m === 5 && $c[2] === self::HYPHEN) {
            foreach ([3, 4] as $j) {
                if (self::isLowerAscii($c[$j])) {
                    $c[$j] -= self::CASE_GAP;
                }
            }
        }

        return $c;
    }

    /**
     * spec/rules/locale-resolution.md 3.3. Exactly two accepted shapes, tested by index rather
     * than by pattern (ARCHITECTURE.md 4.1).
     *
     * @param int[] $c
     */
    private static function acceptedShape(array $c): bool
    {
        return match (count($c)) {
            2 => self::isLowerAscii($c[0]) && self::isLowerAscii($c[1]),
            5 => self::isLowerAscii($c[0]) && self::isLowerAscii($c[1]) && $c[2] === self::HYPHEN
                && self::isUpperAscii($c[3]) && self::isUpperAscii($c[4]),
            default => false,
        };
    }

    /** Alias values are concrete locales by registry invariant (locale-resolution.md 2). */
    private static function aliasTarget(string $tag): ?string
    {
        $reg = self::registry();
        if (!array_key_exists($tag, $reg['aliases'])) {
            return null;
        }

        $target = $reg['aliases'][$tag];
        if (!in_array($target, $reg['locales'], true)) {
            throw new PolytypoException(
                PolytypoException::CODE_MALFORMED_LOCALE_DATA,
                "registry alias \"{$tag}\" points at \"{$target}\", which is not a declared locale",
            );
        }

        return $target;
    }

    /**
     * Exact match before alias, so a registry that wrongly lists a tag in both cannot make lookup
     * order observable (locale-resolution.md 3.4).
     */
    private static function lookup(string $tag): ?string
    {
        $reg = self::registry();
        if (in_array($tag, $reg['locales'], true)) {
            return $tag;
        }

        return self::aliasTarget($tag);
    }

    /**
     * Exact match, then the registry alias table, then the language subtag alone once with no
     * chain. Never a platform locale negotiator (ARCHITECTURE.md 4.7).
     */
    public static function resolve(string $tag): string
    {
        if ($tag === '') {
            throw new PolytypoException(
                PolytypoException::CODE_UNKNOWN_LOCALE,
                'the locale option is required and must be a non-empty string; received ""',
            );
        }

        $c = self::canonicalize($tag);
        if (self::acceptedShape($c)) {
            $canonical = Codepoints::fromCodepoints($c);
            $direct = self::lookup($canonical);
            if ($direct !== null) {
                return $direct;
            }

            if (count($c) === 5) {
                $base = Codepoints::fromCodepoints(array_slice($c, 0, 2));
                $resolved = self::lookup($base);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        $known = implode(', ', self::registry()['locales']);
        throw new PolytypoException(
            PolytypoException::CODE_UNKNOWN_LOCALE,
            "unknown locale \"{$tag}\". Known locales: {$known}.",
        );
    }

    /** @return array<string, mixed> */
    public static function localeData(string $tag): array
    {
        return self::localeDataRaw(self::resolve($tag));
    }
}
