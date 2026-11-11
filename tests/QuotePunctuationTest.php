<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Polytypo\Change;
use Polytypo\Engine\Locale;
use Polytypo\Engine\Registry;
use Polytypo\Polytypo;
use Polytypo\PolytypoException;

/**
 * spec/rules/quote-punctuation.md. The rule is off by default, so every call opts in unless it
 * says it does not; inputs are the author's text and outputs the whole pipeline's, so `quotes`'
 * conversion and this rule's move are seen together. IdempotencyTest never enables the rule, so
 * the release-blocking invariant is repeated here with it on (section 5).
 */
final class QuotePunctuationTest extends TestCase
{
    use TestTrait;

    private const ON = ['quote-punctuation' => true];

    private static function us(string $input, string $mode = 'text'): string
    {
        return Polytypo::transform($input, 'en-US', mode: $mode, rules: self::ON);
    }

    /** @return string[] */
    private static function locales(): array
    {
        $registry = json_decode(
            file_get_contents(dirname(__DIR__) . '/resources/spec/locales/registry.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $registry['locales'];
    }

    #[Test]
    public function sitsBetweenQuotesAndApostropheAndDefaultsToOff(): void
    {
        $position = array_flip(Registry::ruleOrder());
        self::assertSame($position['quotes'] + 1, $position['quote-punctuation']);
        self::assertSame($position['quote-punctuation'] + 1, $position['apostrophe']);
        self::assertFalse(Registry::ruleDefaults()['quote-punctuation']);
    }

    #[Test]
    public function onlyEnUsDeclaresInside(): void
    {
        $inside = [];
        foreach (self::locales() as $tag) {
            $data = json_decode(
                file_get_contents(dirname(__DIR__) . "/resources/spec/locales/{$tag}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertContains($data['quotes']['punctuationPlacement'], ['inside', 'none'], $tag);
            if ($data['quotes']['punctuationPlacement'] === 'inside') {
                $inside[] = $tag;
            }
        }
        self::assertSame(['en-US'], $inside);
    }

    #[Test]
    public function movesACommaAndAFullStopInsideTheClosingMark(): void
    {
        self::assertSame('She said “hello,” then left.', self::us('She said "hello", then left.'));
        self::assertSame('He called it “done.” Then he left.', self::us('He called it "done". Then he left.'));
        self::assertSame('Call it “ready.”', self::us('Call it "ready".'));
        self::assertSame("Call it “ready.”\nNext", self::us("Call it \"ready\".\nNext"));
        self::assertSame('The “first,” the “second.”', self::us('The "first", the "second".'));
        self::assertSame('Both “a,” “b” and “c.”', self::us('Both "a", "b" and "c".'));
        self::assertSame('“Go home.”', self::us('"Go home".'));
    }

    #[Test]
    public function leavesTextThatIsAlreadyInsideAlone(): void
    {
        self::assertSame('She said “hello,” then left.', self::us('She said “hello,” then left.'));
    }

    #[Test]
    public function movesForAnUnpairedCurlyClosingMark(): void
    {
        self::assertSame('Broken pair,” then', self::us('Broken pair”, then'));
    }

    #[Test]
    public function declinesAfterANonLetterAndForEveryOtherMark(): void
    {
        self::assertSame('“Stop!”.', self::us('"Stop!".'));
        self::assertSame('It said “wait…”.', self::us('It said "wait…".'));
        self::assertSame('Use “e.g.”, then', self::us('Use "e.g.", then'));
        self::assertSame('“Route 66”.', self::us('"Route 66".'));
        self::assertSame('“He said ‘hi’”.', self::us('"He said \'hi\'".'));
        self::assertSame('Say “a”; say “b”: say “c”?', self::us('Say "a"; say "b": say "c"?'));
    }

    #[Test]
    public function declinesUnlessThePunctuationEndsItsClause(): void
    {
        self::assertSame('“file”.txt', self::us('"file".txt'));
        self::assertSame('He said (“hi”.)', self::us('He said ("hi".)'));
        self::assertSame('He said “wait”…', self::us('He said "wait"...'));
        self::assertSame('a “b”,”c”', self::us('a “b”,”c”'));
    }

    #[Test]
    public function readsCodePointsNotBytes(): void
    {
        // A multi-byte letter on the left, an astral letter, and a combining mark are all LETTER.
        self::assertSame('Он сказал “да,” потом', self::us('Он сказал "да", потом'));
        self::assertSame('Say “𝒜,” now', self::us('Say "𝒜", now'));
        self::assertSame('Say “e' . "\u{0301}" . ',” now', self::us('Say "e' . "\u{0301}" . '", now'));
    }

    #[Test]
    public function declinesAfterAnAstralNonLetter(): void
    {
        self::assertSame('Say “😀”, now', self::us('Say "😀", now'));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function malformedPlacements(): array
    {
        return [
            'missing field' => [['quotes' => ['primary' => []]]],
            'missing quotes' => [[]],
            'unknown value' => [['quotes' => ['punctuationPlacement' => 'outside']]],
            'wrong type' => [['quotes' => ['punctuationPlacement' => true]]],
            'null' => [['quotes' => ['punctuationPlacement' => null]]],
        ];
    }

    #[Test]
    #[DataProvider('malformedPlacements')]
    public function rejectsMalformedPunctuationPlacement(array $data): void
    {
        try {
            Locale::validatePunctuationPlacement($data, 'xx');
            self::fail('expected an exception');
        } catch (PolytypoException $e) {
            self::assertSame(PolytypoException::CODE_MALFORMED_LOCALE_DATA, $e->getErrorCode());
        }
    }

    #[Test]
    public function acceptsInsideAndNone(): void
    {
        $this->expectNotToPerformAssertions();
        Locale::validatePunctuationPlacement(['quotes' => ['punctuationPlacement' => 'inside']], 'xx');
        Locale::validatePunctuationPlacement(['quotes' => ['punctuationPlacement' => 'none']], 'xx');
    }

    #[Test]
    public function doesNotReadTheSecondaryCloseOrTheApostrophe(): void
    {
        self::assertSame('Check the dogs’, bowls and cats.', self::us('Check the dogs’, bowls and cats.'));
    }

    #[Test]
    public function doesNothingUnlessAsked(): void
    {
        self::assertSame('She said “hello”, then left.', Polytypo::transform('She said "hello", then left.', 'en-US'));
        self::assertSame(
            'She said “hello”, then left.',
            Polytypo::transform('She said "hello", then left.', 'en-US', rules: []),
        );
        self::assertSame(
            'She said “hello”, then left.',
            Polytypo::transform('She said "hello", then left.', 'en-US', rules: ['quote-punctuation' => false]),
        );
    }

    #[Test]
    public function isANoOpInALocaleWhosePlacementIsNone(): void
    {
        self::assertSame(
            'She said ‘hello’, then left.',
            Polytypo::transform('She said "hello", then left.', 'en-GB', rules: self::ON),
        );
        self::assertSame(
            'Er sagte „Hallo“, dann ging er.',
            Polytypo::transform('Er sagte „Hallo“, dann ging er.', 'de-DE', rules: self::ON),
        );
    }

    #[Test]
    public function htmlSpanBoundariesDeclineOnEitherSide(): void
    {
        self::assertSame(
            '<p>She said “hello,” then left.</p>',
            self::us('<p>She said "hello", then left.</p>', 'html'),
        );
        self::assertSame(
            '<p>She said <em>“hello”</em>, then left.</p>',
            self::us('<p>She said <em>"hello"</em>, then left.</p>', 'html'),
        );
        self::assertSame(
            '<p>She said “<em>hello</em>”, then left.</p>',
            self::us('<p>She said "<em>hello</em>", then left.</p>', 'html'),
        );
        self::assertSame(
            '<p>She said “hello”.</p><p>Next.</p>',
            self::us('<p>She said "hello".</p><p>Next.</p>', 'html'),
        );
        self::assertSame('<p>Call it “ready.”</p>', self::us('<p>Call it "ready".</p>', 'html'));
    }

    #[Test]
    public function yamlPlainScalarIsOneSpan(): void
    {
        self::assertSame(
            'title: She said “hello,” then left.',
            Polytypo::transform('title: She said "hello", then left.', 'en-US', mode: 'yaml', rules: self::ON, keys: ['title']),
        );
    }

    #[Test]
    public function protectedRunOnEitherSideBlocksTheMove(): void
    {
        $range = static function (string $input, string $needle): array {
            $start = mb_strlen(substr($input, 0, strpos($input, $needle)), 'UTF-8');

            return [['start' => $start, 'end' => $start + mb_strlen($needle, 'UTF-8')]];
        };

        $a = 'Set "file".${ext} now';
        self::assertSame('Set “file”.${ext} now', Polytypo::transform($a, 'en-US', rules: self::ON, protect: $range($a, '${ext}')));
        $b = 'Use "${name}", then stop.';
        self::assertSame('Use “${name}”, then stop.', Polytypo::transform($b, 'en-US', rules: self::ON, protect: $range($b, '${name}')));
        $c = 'Say "hi", then ${x}.';
        self::assertSame('Say “hi,” then ${x}.', Polytypo::transform($c, 'en-US', rules: self::ON, protect: $range($c, '${x}')));
    }

    #[Test]
    public function analyzeReportsOneChangePerMoveAndOnlyWhenEnabled(): void
    {
        $ids = static fn (array $changes): array => array_map(static fn (Change $c): string => $c->ruleId, $changes);
        $moves = array_filter(
            $ids(Polytypo::analyze('The "first", the "second".', 'en-US', rules: self::ON)),
            static fn (string $id): bool => $id === 'quote-punctuation',
        );
        self::assertCount(2, $moves);
        self::assertNotContains('quote-punctuation', $ids(Polytypo::analyze('The "first", the "second".', 'en-US')));
    }

    private static function assertIdempotent(string $text, string $locale): void
    {
        $once = Polytypo::transform($text, $locale, rules: self::ON);
        $twice = Polytypo::transform($once, $locale, rules: self::ON);
        self::assertSame($once, $twice, "locale={$locale} input=" . json_encode($text));
    }

    #[Test]
    public function isIdempotentOverABiasedAlphabetInEveryLocale(): void
    {
        $alphabet = [
            '"', "'", '“', '”', '‘', '’', '«', '»', 'a', 'B', '1', ',', '.', ';', '!', '…',
            ' ', "\u{00A0}", "\n", '(', ')', '-', '—',
        ];

        $this->forAll(
            Generators::elements(...self::locales()),
            Generators::map(
                static fn (array $chars): string => implode('', $chars),
                Generators::seq(Generators::elements(...$alphabet)),
            ),
        )->then(function (string $locale, string $text): void {
            self::assertIdempotent($text, $locale);
        });
    }

    #[Test]
    public function isIdempotentOverEveryShortStringInEnUs(): void
    {
        $alphabet = ['"', '”', '“', '’', 'a', '1', ',', '.', ' '];
        $frontier = [''];
        $moved = 0;
        for ($n = 0; $n < 6; $n++) {
            $next = [];
            foreach ($frontier as $prefix) {
                foreach ($alphabet as $ch) {
                    $candidate = $prefix . $ch;
                    $next[] = $candidate;
                    self::assertIdempotent($candidate, 'en-US');
                    if (self::us($candidate) !== Polytypo::transform($candidate, 'en-US')) {
                        $moved++;
                    }
                }
            }
            $frontier = $next;
        }
        // The sweep must reach the rule, or it proves nothing.
        self::assertGreaterThan(0, $moved);
    }
}
