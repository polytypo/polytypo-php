<?php

declare(strict_types=1);

namespace Polytypo\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Polytypo\Polytypo;

/** spec/rules/modes.md 3.6 (spec 1.30.0) -- the published skip list. */
final class SkippedElementsTest extends TestCase
{
    #[Test]
    public function itIsTheTenNamesInSpecOrder(): void
    {
        self::assertSame(
            ['code', 'pre', 'kbd', 'samp', 'var', 'script', 'style', 'textarea', 'svg', 'math'],
            Polytypo::SKIPPED_ELEMENTS,
        );
    }

    #[Test]
    public function everyNamedElementKeepsItsSubtree(): void
    {
        foreach (Polytypo::SKIPPED_ELEMENTS as $tag) {
            $out = Polytypo::transform("<p>a...b</p><{$tag}>c...d</{$tag}>", 'en-US', mode: 'html');
            self::assertSame("<p>a\u{2026}b</p><{$tag}>c...d</{$tag}>", $out, $tag);
        }
    }
}
