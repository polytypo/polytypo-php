<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;
use Polytypo\PolytypoException;

/**
 * Black-box cross-check, same technique as every other port: transforming a probe string via the
 * raw tag must produce byte-identical output to transforming it via the already-resolved
 * canonical locale -- this exercises Polytypo::transform's actual resolution path end to end,
 * not Locale::resolve() in isolation.
 */
final class LocaleResolutionTest extends TestCase
{
    private const PROBES = [
        'He said "so" -- and left...',
        "Pages 1999-2005, see  p. 7 .",
        'Really?.. 50 % (c) 2026',
    ];

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function cases(): array
    {
        $data = json_decode(
            file_get_contents(dirname(__DIR__) . '/resources/spec/fixtures/locale-resolution.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $out = [];
        foreach ($data['cases'] as $case) {
            $out[$case['id']] = [$case];
        }

        return $out;
    }

    #[DataProvider('cases')]
    public function testResolution(array $case): void
    {
        $tag = ($case['tagAbsent'] ?? false) ? '' : $case['tag'];

        if (isset($case['throws'])) {
            try {
                Polytypo::transform('plain text', $tag);
                self::fail('expected ' . $case['throws'] . ' to be thrown');
            } catch (PolytypoException $e) {
                self::assertSame($case['throws'], $e->getErrorCode());
            }

            return;
        }

        foreach (self::PROBES as $probe) {
            $viaTag = Polytypo::transform($probe, $tag);
            $viaCanonical = Polytypo::transform($probe, $case['resolves']);
            self::assertSame($viaCanonical, $viaTag);
        }
    }
}
