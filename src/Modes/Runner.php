<?php

declare(strict_types=1);

namespace Polytypo\Modes;

use Polytypo\Engine\Edits;
use Polytypo\Engine\Pipeline;
use Polytypo\Engine\Registry;
use Polytypo\Engine\RuleContext;

/**
 * modes.md 3.5. The pipeline runs once, over the marker-separated concatenation of every
 * processable span -- not per span (would pair quotation marks in isolation), and not over a
 * naive concatenation (would manufacture adjacencies the document does not have).
 */
final class Runner
{
    private function __construct()
    {
    }

    /**
     * The same sequence as Engine\Pipeline::runRules, with the two boundary filters of
     * modes.md 3.4 interposed.
     *
     * @param int[] $cp
     * @param string[] $plan
     * @param array<string, mixed> $localeData
     * @return int[]
     */
    private static function runRulesOverSpans(array $cp, array $plan, array $localeData, RuleContext $ctx): array
    {
        $current = $cp;
        foreach ($plan as $ruleId) {
            $fn = Registry::rule($ruleId);
            $edits = $fn($current, $localeData, $ctx);
            $filtered = Spans::filterBoundaryEdits($current, $edits, Spans::spanRangesOf($current));
            if ($filtered === []) {
                continue;
            }
            $current = Edits::applyEdits($current, $filtered, $ruleId);
        }

        return $current;
    }

    /**
     * The output is the input with a set of disjoint substring replacements applied and nothing
     * else (modes.md 4). A span whose content the rules did not change contributes no
     * replacement, so a document needing no changes comes back byte-identical.
     *
     * $sourceCp is the whole input already converted to code points; spans address it by
     * code-point index.
     *
     * @param int[] $sourceCp
     * @param Span[] $spans
     * @param string[] $plan
     * @param array<string, mixed> $localeData
     */
    public static function runOverSpans(
        array $sourceCp,
        array $spans,
        array $plan,
        array $localeData,
        RuleContext $ctx,
    ): string {
        $normalized = Spans::normalizeSpans($spans);
        if ($normalized === []) {
            return \Polytypo\Engine\Codepoints::fromCodepoints($sourceCp);
        }

        $concatenated = Spans::concatenateSpans($sourceCp, $normalized);
        $transformed = self::runRulesOverSpans($concatenated, $plan, $localeData, $ctx);
        $pieces = Spans::splitOnMarker($transformed, count($normalized));

        $out = [];
        $cursor = 0;
        foreach ($normalized as $i => $span) {
            $piece = $pieces[$i];
            $original = array_slice($sourceCp, $span->start, $span->end - $span->start);
            for ($j = $cursor; $j < $span->start; $j++) {
                $out[] = $sourceCp[$j];
            }
            array_push($out, ...($piece === $original ? $original : $piece));
            $cursor = $span->end;
        }
        for ($j = $cursor; $j < count($sourceCp); $j++) {
            $out[] = $sourceCp[$j];
        }

        return \Polytypo\Engine\Codepoints::fromCodepoints($out);
    }

    /**
     * runOverSpans, reporting instead of applying (analyze.md section 1). The span table supplies
     * the origin map, so every change comes back in DOCUMENT coordinates -- analyze.md section 6
     * names a runtime that reports span-local offsets here as the mistake that passes every
     * text-mode test.
     *
     * @param int[] $sourceCp
     * @param Span[] $spans
     * @param string[] $plan
     * @param array<string, mixed> $localeData
     * @return \Polytypo\Change[]
     */
    public static function analyzeOverSpans(
        array $sourceCp,
        array $spans,
        array $plan,
        array $localeData,
        RuleContext $ctx,
    ): array {
        $normalized = Spans::normalizeSpans($spans);
        if ($normalized === []) {
            return [];
        }

        return Pipeline::runRulesRecording(
            Spans::concatenateSpans($sourceCp, $normalized),
            $plan,
            $localeData,
            $ctx,
            Spans::originOfSpans($normalized),
            count($sourceCp),
            static fn (array $current, array $edits): array => Spans::filterBoundaryEdits(
                $current,
                $edits,
                Spans::spanRangesOf($current),
            ),
        );
    }
}
