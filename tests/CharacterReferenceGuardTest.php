<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;

/**
 * nbsp.md 3.3 step 4 (spec 1.3.0). text mode has no markup concept, so `fr` -- whose
 * narrowBeforePunctuation lists ";" -- used to insert U+202F before the ";" that *ends* a
 * character reference, and "Bonjour&#160;: oui" stopped being a reference at all. The conformance
 * fixtures cover this (fr-nbsp-character-reference-*), but only once the vendored spec is
 * refreshed; this test is what fails today if the guard is removed.
 */
final class CharacterReferenceGuardTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function cases(): array
    {
        return [
            ['Bonjour&#160;: oui', 'Bonjour&#160;: oui'],
            ['Tom &amp; Jerry', 'Tom &amp; Jerry'],
            ['a &notaname; b', 'a &notaname; b'],
            ['Oui ; non', "Oui\u{202F}; non"],
            ['Section 4; suite', "Section 4\u{202F}; suite"],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function guardsOnlyReferenceShapedSemicolons(string $input, string $expected): void
    {
        self::assertSame($expected, Polytypo::transform($input, 'fr'));
    }
}
