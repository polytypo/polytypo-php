<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;

/**
 * spec/rules/quotes.md 3.1, 3.2 and 3.5 (spec 1.33.0): inverted-mark OPENISH, the quoted-lone-mark
 * veto and the bounded certification gate.
 */
final class Quotes133Test extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function cases(): array
    {
        return [
            'opens after inverted question mark' => ['es', '¿"Sí"?', '¿«Sí»?'],
            'opens after inverted exclamation mark' => ['es', '¡"Hola"!', '¡«Hola»!'],
            'inverted mark in English prose' => ['en-US', '¡"Hola"!', '¡“Hola”!'],
            'lone mark in single quotes is left' => ['en-US', 'Press \'"\' for a quote and \'x\' for exit.', 'Press \'"\' for a quote and “x” for exit.'],
            'lone mark does not flip the pair before it' => ['en-US', '\'he\' \'"\'', '“he” \'"\''],
            'lone single quote in double quotes' => ['en-US', '"\'"', '"\'"'],
            'lone mark in en-GB' => ['en-GB', '\'he\' \'"\'', '‘he’ \'"\''],
        ];
    }

    #[DataProvider('cases')]
    public function testCases(string $locale, string $input, string $want): void
    {
        $rules = ['ranges' => false];
        $got = Polytypo::transform($input, $locale, rules: $rules);
        $this->assertSame($want, $got);
        $this->assertSame($got, Polytypo::transform($got, $locale, rules: $rules), 'second call');
    }

    public function testGateRoundBoundKeepsLongInputAFixedPoint(): void
    {
        $rules = ['ranges' => false];
        $got = Polytypo::transform(str_repeat('\'"a"', 400), 'en-US', rules: $rules);
        $this->assertSame($got, Polytypo::transform($got, 'en-US', rules: $rules));
    }
}
