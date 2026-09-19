<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;
use Polytypo\PolytypoException;

/** spec/rules/nbsp.md 3.1a -- `narrowNbsp` moves NARROW-TARGET, it does not post-process. */
final class NarrowNbspTest extends TestCase
{
    private const NBSP = "\u{00a0}";
    private const NNBSP = "\u{202f}";

    private static function fr(string $input, ?string $narrowNbsp = null): string
    {
        return Polytypo::transform($input, 'fr', narrowNbsp: $narrowNbsp);
    }

    public function testWritesNbspWhereN2WouldWriteNnbsp(): void
    {
        $input = 'Un délai ? Vraiment ! Et puis ; voilà.';
        $nb = self::NBSP;
        $nn = self::NNBSP;
        $this->assertSame("Un délai{$nn}? Vraiment{$nn}! Et puis{$nn}; voilà.", self::fr($input));
        $this->assertSame(
            "Un délai{$nb}? Vraiment{$nb}! Et puis{$nb}; voilà.",
            self::fr($input, 'nbsp'),
        );
    }

    public function testNormalisesAnAuthoredNarrowSpaceAtAClaimedIndex(): void
    {
        $nb = self::NBSP;
        $nn = self::NNBSP;
        $this->assertSame("Oui{$nb}?", self::fr("Oui{$nn}?", 'nbsp'));
        $this->assertSame("Oui{$nn}?", self::fr("Oui{$nn}?"));
        // Elsewhere it is left alone: the option changes what is written, not which indices are
        // claimed.
        $this->assertSame("mot{$nn}mot", self::fr("mot{$nn}mot", 'nbsp'));
    }

    public function testClaimsTheSameIndicesUnderTheSameGuards(): void
    {
        $nb = self::NBSP;
        $this->assertSame("12:30 et http://x{$nb}; oui", self::fr('12:30 et http://x ; oui', 'nbsp'));
        // N1 (the colon) and N8 (fr's primary pair, innerSpace "nbsp") already wrote U+00A0.
        $this->assertSame(
            "Il a dit{$nb}: «{$nb}oui{$nb}»{$nb}; puis{$nb}?",
            self::fr('Il a dit : « oui » ; puis ?', 'nbsp'),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function localesWithoutNarrowSpaces(): array
    {
        return ['en-US' => ['en-US'], 'de-DE' => ['de-DE'], 'ru' => ['ru']];
    }

    #[DataProvider('localesWithoutNarrowSpaces')]
    public function testIsANoOpWhereNothingEmitsANarrowSpace(string $locale): void
    {
        $input = 'She said “hi” — really...';
        $this->assertSame(
            Polytypo::transform($input, $locale),
            Polytypo::transform($input, $locale, narrowNbsp: 'nbsp'),
        );
    }

    public function testIsAFixedPointUnderTheOption(): void
    {
        $nn = self::NNBSP;
        foreach (['Un délai ? Vraiment !', "Oui{$nn}?", 'Il a dit : « oui » ;'] as $input) {
            $once = self::fr($input, 'nbsp');
            $this->assertSame($once, self::fr($once, 'nbsp'), $input);
        }
    }

    public function testPostProcessingTheDefaultOutputIsNotAFixedPoint(): void
    {
        // Which is why the option moves the target instead: a caller's str_replace is stable
        // only as long as it always runs. Feed it back through the default pipeline and N2
        // converts it straight back.
        $nb = self::NBSP;
        $nn = self::NNBSP;
        $postProcessed = str_replace($nn, $nb, self::fr('Un délai ?'));
        $this->assertSame("Un délai{$nb}?", $postProcessed);
        $this->assertSame("Un délai{$nn}?", self::fr($postProcessed));
    }

    public function testValidationAndItsOrder(): void
    {
        $codeOf = static function (callable $run): string {
            try {
                $run();
            } catch (PolytypoException $e) {
                return $e->getErrorCode();
            }

            return 'NO THROW';
        };

        $this->assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            $codeOf(static fn () => Polytypo::transform('x', 'fr', narrowNbsp: 'wide')),
        );
        $this->assertSame(
            PolytypoException::CODE_INVALID_MODE,
            $codeOf(static fn () => Polytypo::transform('x', 'fr', 'yaml', narrowNbsp: 'wide')),
        );
        $this->assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            $codeOf(static fn () => Polytypo::transform('x', 'fr', rules: ['nope' => true], narrowNbsp: 'wide')),
        );
        $this->assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            $codeOf(static fn () => Polytypo::transform('x', 'xx', narrowNbsp: 'wide')),
        );
        // The check belongs to the call, not to the rule.
        $this->assertSame(
            PolytypoException::CODE_INVALID_OPTION,
            $codeOf(static fn () => Polytypo::transform('x', 'fr', rules: ['nbsp' => false], narrowNbsp: 'wide')),
        );
    }

    public function testAcceptsTheExplicitDefault(): void
    {
        $nn = self::NNBSP;
        $this->assertSame("Un délai{$nn}?", self::fr('Un délai ?', 'narrow'));
    }
}
