<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;

/**
 * spec/rules/dashes.md 3.4a and 5.2a (spec 1.18.0) -- `dash.parenthetical = "em-paired"`, which
 * only `es` carries: a sentence with exactly two eligible strokes becomes `a —b— c`.
 */
final class EmPairedDashesTest extends TestCase
{
    private const EM = "\u{2014}";

    /** @return array<string, array{0: string, 1: string}> */
    public static function cases(): array
    {
        $em = self::EM;

        return [
            'spaced hyphens' => ['Llegó - por fin - a casa', "Llegó {$em}por fin{$em} a casa"],
            'already correct' => ["Llegó {$em}por fin{$em} a casa", "Llegó {$em}por fin{$em} a casa"],
            'em-spaced both sides' => ["Llegó {$em} por fin {$em} a casa", "Llegó {$em}por fin{$em} a casa"],
            'tight hyphens' => ['Llegó-por fin-a casa', 'Llegó-por fin-a casa'],
            'tight em dashes insert outer spaces' => ["Llegó{$em}por fin{$em}a casa", "Llegó {$em}por fin{$em} a casa"],
            'lone stroke untouched' => ['Madrid - la capital', 'Madrid - la capital'],
            'three strokes untouched' => ['Madrid - Barcelona - Sevilla - Bilbao', 'Madrid - Barcelona - Sevilla - Bilbao'],
            'dialogue dash untouched' => ["{$em}Hola {$em}dijo{$em}.", "{$em}Hola {$em}dijo{$em}."],
            'terminal splits the segment' => ['Llegó - por fin. Salió - rápido.', 'Llegó - por fin. Salió - rápido.'],
            'bracket sides stay tight' => ["Llegó ({$em}por fin{$em}) ya", "Llegó ({$em}por fin{$em}) ya"],
            'tight dash before a stop gets no space' => ["Llegó {$em}por fin{$em}.", "Llegó {$em}por fin{$em}."],
            'one-sided hyphen is not a stroke' => ['(- por fin -)', '(- por fin -)'],
            'compound hyphen is not a stroke' => ['Llegó - por fin - a la ex-casa', "Llegó {$em}por fin{$em} a la ex-casa"],
            'range is not a stroke' => ['Llegó - en 1990-2000 - a casa', "Llegó {$em}en 1990-2000{$em} a casa"],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function convertsOnlyAPairedSegment(string $input, string $want): void
    {
        $got = Polytypo::transform($input, 'es');
        $this->assertSame($want, $got);
        $this->assertSame($got, Polytypo::transform($got, 'es'), 'idempotent');
    }

    #[Test]
    public function isIdempotentOverAnExhaustiveSweepForEs(): void
    {
        $alphabet = ['a', '1', ' ', '-', "\u{2014}", '.', '(', "\u{00A0}", "\n"];
        $count = count($alphabet);
        $checked = 0;
        for ($len = 1; $len <= 5; $len++) {
            $total = $count ** $len;
            for ($n = 0; $n < $total; $n++) {
                $s = '';
                $x = $n;
                for ($k = 0; $k < $len; $k++) {
                    $s .= $alphabet[$x % $count];
                    $x = intdiv($x, $count);
                }
                $once = Polytypo::transform($s, 'es');
                if ($once !== Polytypo::transform($once, 'es')) {
                    $this->fail('not idempotent: ' . json_encode($s, JSON_UNESCAPED_UNICODE));
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(50000, $checked);
    }
}
