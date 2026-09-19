<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;
use Polytypo\PolytypoException;

/**
 * The conformance runner (docs/ARCHITECTURE.md section 6). Every case in
 * resources/spec/fixtures/ is driven through the public Polytypo::transform, and every
 * non-throwing case is also an idempotency case. Nothing here knows about individual locales or
 * rules: fixtures are discovered at test-run time, mirroring the other four ports' own runners.
 *
 * Every `mode === "markdown"` case is skipped, not just `dialect === "mdx"`: this runtime
 * implements no markdown dialect at all (see docs/ROADMAP.md and spec/CONFORMANCE.md) -- a
 * strictly larger, honestly-declared gap than the mdx-only gap the other four runtimes carry.
 */
final class ConformanceTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function cases(): array
    {
        $fixturesDir = dirname(__DIR__) . '/resources/spec/fixtures';
        $files = glob($fixturesDir . '/*.json');
        sort($files);

        $out = [];
        foreach ($files as $file) {
            $basename = basename($file);
            if ($basename === 'locale-resolution.json') {
                continue;
            }
            $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach ($data['cases'] as $case) {
                $case['locale'] = $data['locale'];
                $out["{$basename}::{$case['id']}"] = [$basename, $case];
            }
        }

        return $out;
    }

    #[DataProvider('cases')]
    public function testFixture(string $file, array $case): void
    {
        if (($case['mode'] ?? 'text') === 'markdown') {
            self::markTestSkipped(
                'markdown mode is not implemented by this runtime (POLYTYPO_INVALID_DIALECT) -- '
                . 'an accepted, honestly-declared conformance gap; see spec/CONFORMANCE.md',
            );
        }

        $locale = $case['locale'];
        $mode = $case['mode'] ?? 'text';
        $rules = $case['rules'] ?? null;
        // Spec 1.3.0's case-level option (nbsp.md 3.1a). It is passed on BOTH calls below: a
        // case carrying it is a fixed point under its own options and not under the defaults,
        // so carrying it into the idempotency re-run is contract (ARCHITECTURE.md 6.1), not
        // convenience.
        $narrowNbsp = $case['narrowNbsp'] ?? null;

        if (isset($case['throws'])) {
            try {
                Polytypo::transform($case['in'], $locale, mode: $mode, rules: $rules, narrowNbsp: $narrowNbsp);
                self::fail('expected ' . $case['throws'] . ' to be thrown');
            } catch (PolytypoException $e) {
                self::assertSame($case['throws'], $e->getErrorCode());
            }

            return;
        }

        $expected = $case['out'];
        $got = Polytypo::transform($case['in'], $locale, mode: $mode, rules: $rules, narrowNbsp: $narrowNbsp);
        self::assertSame($expected, $got, "out = " . self::escape($got) . ', want ' . self::escape($expected));

        // Free coverage, and the most common port bug (ARCHITECTURE.md 6.1).
        $twice = Polytypo::transform($expected, $locale, mode: $mode, rules: $rules, narrowNbsp: $narrowNbsp);
        self::assertSame($expected, $twice, 'not idempotent: transform(out) = ' . self::escape($twice));
    }

    private static function escape(string $s): string
    {
        $out = '';
        foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            $out .= $cp > 0x7E ? sprintf('\\u{%04x}', $cp) : $ch;
        }

        return $out;
    }
}
