<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Codepoints;
use Polytypo\Engine\Edit;
use Polytypo\Engine\RuleContext;
use Polytypo\Engine\Sentinels;
use Polytypo\Engine\UnicodeUtil;

/**
 * spec/rules/quote-punctuation.md 3. Off by default. Reads one locale field,
 * `quotes.punctuationPlacement`, and the primary close glyph; "none" makes the rule a no-op. A
 * single left-to-right scan over the code-point array: no regex, no byte offsets, no mb_* calls.
 */
final class QuotePunctuationRule
{
    private const COMMA = 0x2C;
    private const FULL_STOP = 0x2E;

    private function __construct()
    {
    }

    /** cp[i], or Sentinels::NONE if i is out of bounds -- the spec's own boundary value. */
    private static function at(array $cp, int $i): int
    {
        if ($i < 0 || $i >= count($cp)) {
            return Sentinels::NONE;
        }

        return $cp[$i];
    }

    /**
     * SPACELIKE (quotes.md 3.1) = INLINE-SPACE u BREAK. The inline marker is in neither, so it
     * fails C3 / C2 / C4 wherever it stands (modes.md 3.3); LINE_MARKER is in BREAK for every rule.
     */
    private static function isSpacelike(int $cp): bool
    {
        return QuoteAmbiguity::isInlineSpace($cp)
            || $cp === 0x0A || $cp === 0x0D || $cp === 0x0B || $cp === 0x0C
            || $cp === 0x85 || $cp === 0x2028 || $cp === 0x2029
            || $cp === Sentinels::LINE_MARKER;
    }

    /**
     * @param int[] $cp
     * @param array<string, mixed> $localeData
     * @return Edit[]
     */
    public static function scan(array $cp, array $localeData, RuleContext $ctx): array
    {
        if (($localeData['quotes']['punctuationPlacement'] ?? null) !== 'inside') {
            return [];
        }

        $closeGlyph = Codepoints::toCodepoints($localeData['quotes']['primary']['close']);
        if ($closeGlyph === []) {
            return [];
        }
        $close = $closeGlyph[0];

        $n = count($cp);
        $edits = [];
        $i = 0;

        // quote-punctuation.md 3.2: C1-C4; after an edit continue from i + 2.
        while ($i + 1 < $n) {
            $next = $cp[$i + 1];
            $after = self::at($cp, $i + 2);
            if (
                $cp[$i] === $close
                && ($next === self::COMMA || $next === self::FULL_STOP)
                && $i >= 1
                && UnicodeUtil::isLetter($cp[$i - 1])
                && ($after === Sentinels::NONE || self::isSpacelike($after))
            ) {
                $edits[] = new Edit($i, $i + 2, [$next, $close], 'quote-punctuation');
                $i += 2;
                continue;
            }
            $i++;
        }

        return $edits;
    }
}
