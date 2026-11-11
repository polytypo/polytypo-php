<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;

/**
 * spec/rules/nbsp.md 3.7 (spec 1.22.0): N5 inserts for spacedUnits and deletes for closedUnits.
 * Every such edit changes what a later pass sees, so the claim worth proving is the fixed point.
 */
final class NbspUnitsTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function exampleCases(): array
    {
        $n = self::NBSP;

        return [
            'spaced inserts' => ['de-DE', 'Es sind 20km', "Es sind 20{$n}km"],
            'closed deletes a space' => ['en-US', 'Rose 5 %', 'Rose 5%'],
            'closed deletes a nbsp' => ['ru', "Rose 5{$n}%", 'Rose 5%'],
            'beforeUnits converts only' => ['cs', '14 % nebo 20%', "14{$n}% nebo 20%"],
            'compound' => ['de-DE', '15cm-Norm', '15cm-Norm'],
            'file name' => ['de-DE', 'foto-5kg.png', 'foto-5kg.png'],
            'path' => ['de-DE', '/5km', '/5km'],
            'identifier' => ['de-DE', 'x_5kg', 'x_5kg'],
            'letter guard H2km' => ['de-DE', 'H2km', 'H2km'],
            'letter guard A4kg' => ['de-DE', 'A4kg', 'A4kg'],
            'modulo is an operation' => ['en-US', '10 % 3', '10 % 3'],
        ];
    }

    #[DataProvider('exampleCases')]
    public function testExamples(string $locale, string $input, string $want): void
    {
        $this->assertSame($want, Polytypo::transform($input, $locale, 'text'));
    }

    public function testBoundedAtomSweepIsAFixedPoint(): void
    {
        $atoms = ['5', ' ', self::NBSP, "\u{202F}", '%', 'km', '°C', '-', '.', ',', 'a', '_', '(', '–', '×'];
        $checked = 0;
        foreach (['de-DE', 'en-US', 'ru', 'cs', 'fr-CA'] as $locale) {
            $walk = function (string $prefix, int $depth) use (&$walk, $atoms, $locale, &$checked): void {
                if ($prefix !== '') {
                    $once = Polytypo::transform($prefix, $locale, 'text');
                    $this->assertSame(
                        $once,
                        Polytypo::transform($once, $locale, 'text'),
                        sprintf('%s: %s', $locale, json_encode($prefix, JSON_UNESCAPED_UNICODE)),
                    );
                    $checked++;
                }
                if ($depth === 0) {
                    return;
                }
                foreach ($atoms as $atom) {
                    $walk($prefix . $atom, $depth - 1);
                }
            };
            $walk('', 3);
        }
        $this->assertGreaterThan(15000, $checked);
    }

    public function testRangeShapesAreFixedPointsWithRangesOn(): void
    {
        $atoms = ['1', '5', '%', '°C', '$', ' ', self::NBSP, '-', '--', '–', '—', 'km', 'a'];
        mt_srand(22);
        foreach (['de-DE', 'en-US', 'ru', 'cs'] as $locale) {
            for ($i = 0; $i < 1500; $i++) {
                $input = '';
                for ($j = mt_rand(4, 16); $j > 0; $j--) {
                    $input .= $atoms[mt_rand(0, count($atoms) - 1)];
                }
                $once = Polytypo::transform($input, $locale, 'text', null, ['ranges' => true]);
                $this->assertSame(
                    $once,
                    Polytypo::transform($once, $locale, 'text', null, ['ranges' => true]),
                    sprintf('%s: %s', $locale, json_encode($input, JSON_UNESCAPED_UNICODE)),
                );
            }
        }
    }

    public function testMultiplicationChainsAreFixedPoints(): void
    {
        $atoms = ['2', '3', ' ', self::NBSP, "\u{202F}", 'x', 'х', 'kg', 'км', '%'];
        $checked = 0;
        foreach (['de-DE', 'en-US', 'ru', 'cs'] as $locale) {
            $walk = function (string $prefix, int $depth) use (&$walk, $atoms, $locale, &$checked): void {
                if ($prefix !== '') {
                    $once = Polytypo::transform($prefix, $locale, 'text');
                    $this->assertSame(
                        $once,
                        Polytypo::transform($once, $locale, 'text'),
                        sprintf('%s: %s', $locale, json_encode($prefix, JSON_UNESCAPED_UNICODE)),
                    );
                    $checked++;
                }
                if ($depth === 0) {
                    return;
                }
                foreach ($atoms as $atom) {
                    $walk($prefix . $atom, $depth - 1);
                }
            };
            $walk('', 5);
        }
        $this->assertGreaterThan(70000, $checked);
    }
}
