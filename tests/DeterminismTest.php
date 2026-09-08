<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;

/**
 * ARCHITECTURE.md section 7: transform() must be pure -- no I/O, no globals, no static mutable
 * state -- reentrant regardless of call order. Go and Ruby each prove "no shared mutable state"
 * directly under real parallelism (goroutines / Threads); PHP's stock execution model has no
 * built-in threading to exercise the same way. This is the honest substitute: many interleaved
 * calls across different locales and modes in one process, asserting no call's registry/locale
 * caches leak into another's result.
 */
final class DeterminismTest extends TestCase
{
    #[Test]
    public function interleavedCallsAcrossLocalesAndModesDoNotContaminateEachOther(): void
    {
        $registry = json_decode(
            file_get_contents(dirname(__DIR__) . '/resources/spec/locales/registry.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $locales = $registry['locales'];

        $inputs = [
            'text' => 'He said "hello" -- and left... 5-10 pages (c) 2026',
            'html' => '<p>He said "hello" -- <code>a -- b</code> and left...</p>',
        ];

        // Establish the expected output for each (locale, mode) pair in isolation first.
        $expected = [];
        foreach ($locales as $locale) {
            foreach ($inputs as $mode => $input) {
                $expected["{$locale}:{$mode}"] = Polytypo::transform($input, $locale, mode: $mode);
            }
        }

        // Then call them again in a shuffled, interleaved order many times, and confirm every
        // result still matches its isolated baseline -- no call leaves state behind that could
        // change a later call's answer.
        $keys = array_keys($expected);
        for ($round = 0; $round < 20; $round++) {
            shuffle($keys);
            foreach ($keys as $key) {
                [$locale, $mode] = explode(':', $key, 2);
                $got = Polytypo::transform($inputs[$mode], $locale, mode: $mode);
                self::assertSame($expected[$key], $got, "round {$round}, {$key}");
            }
        }
    }
}
