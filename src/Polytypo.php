<?php

declare(strict_types=1);

namespace Polytypo;

use Polytypo\Engine\Codepoints;
use Polytypo\Engine\Pipeline;
use Polytypo\Engine\RuleContext;
use Polytypo\Modes\Html;
use Polytypo\Modes\Runner;

/**
 * polytypo normalizes typography across languages: locale-correct quotes, dashes, ellipses,
 * apostrophes, symbols and no-break spaces, from a spec shared across every polytypo runtime
 * (github.com/polytypo/polytypo). See spec/CONFORMANCE.md there for exactly what this runtime
 * implements -- notably, `mode: "markdown"` is not implemented in this runtime (see the class
 * doc on that branch below).
 */
final class Polytypo
{
    private function __construct()
    {
    }

    /**
     * Applies polytypo's rule pipeline to $input and returns the result.
     *
     * $locale is required, with no default -- an unknown locale throws PolytypoException with
     * CODE_UNKNOWN_LOCALE; there is never a fallback to English. $mode is "text" (default),
     * "html" or "markdown". $dialect is required iff $mode is "markdown" -- but no markdown
     * dialect is implemented by this runtime (see below), so any markdown call throws
     * CODE_INVALID_DIALECT. $rules is an opt-out array keyed by rule id.
     *
     * Pure: no I/O, no environment, no clock, no globals, no static mutable state beyond
     * memoized-once, never-mutated-after spec data -- reentrant and safe to call from any
     * context (ARCHITECTURE.md section 7).
     *
     * @param array<string, bool>|null $rules
     */
    public static function transform(
        string $input,
        string $locale,
        string $mode = 'text',
        ?string $dialect = null,
        ?array $rules = null,
    ): string {
        $resolvedMode = self::resolveMode($mode);

        return match ($resolvedMode) {
            'text' => self::transformText($input, $locale, $dialect, $rules),
            'html' => self::transformHtml($input, $locale, $dialect, $rules),
            // 'markdown': no dialect is implemented by this runtime (verified this session:
            // league/commonmark gives no position data at all on inline text nodes, and no other
            // maintained PHP CommonMark/GFM library with the needed raw-extent span property
            // exists -- see docs/ROADMAP.md). Rules and locale are still validated first, via
            // transformMarkdown's own call to Pipeline::prepare, to keep error-precedence order
            // identical to every other runtime even on a call that will ultimately fail.
            default => self::transformMarkdown($input, $locale, $dialect, $rules),
        };
    }

    private static function resolveMode(string $mode): string
    {
        return match ($mode) {
            'text' => 'text',
            'html', 'markdown' => $mode,
            default => throw new PolytypoException(
                PolytypoException::CODE_INVALID_MODE,
                "unknown mode \"{$mode}\". Expected \"text\", \"html\" or \"markdown\"",
            ),
        };
    }

    /** @param array<string, bool>|null $rules */
    private static function transformText(string $input, string $locale, ?string $dialect, ?array $rules): string
    {
        if ($dialect !== null) {
            throw new PolytypoException(
                PolytypoException::CODE_INVALID_DIALECT,
                '"dialect" is only valid when mode is "markdown"',
            );
        }
        [, $localeData, $plan] = Pipeline::prepare($locale, $rules);
        $cp = Codepoints::toCodepoints($input);
        $ctx = new RuleContext(mode: 'text', dialect: null, locale: $locale);
        $result = Pipeline::runRules($cp, $plan, $localeData, $ctx);

        return Codepoints::fromCodepoints($result);
    }

    /** @param array<string, bool>|null $rules */
    private static function transformHtml(string $input, string $locale, ?string $dialect, ?array $rules): string
    {
        if ($dialect !== null) {
            throw new PolytypoException(
                PolytypoException::CODE_INVALID_DIALECT,
                '"dialect" is only valid when mode is "markdown"',
            );
        }
        [$resolvedLocale, $localeData, $plan] = Pipeline::prepare($locale, $rules);
        $spans = Html::htmlSpans($input);
        $ctx = new RuleContext(mode: 'html', dialect: null, locale: $resolvedLocale);
        $cp = Codepoints::toCodepoints($input);

        return Runner::runOverSpans($cp, $spans, $plan, $localeData, $ctx);
    }

    /**
     * Validation order is public, tested behaviour, identical across every runtime: rules (an
     * unknown rule id), then locale (an unknown locale), then dialect. No markdown dialect is
     * implemented by this runtime, so this always throws CODE_INVALID_DIALECT once rules/locale
     * have both validated successfully.
     *
     * @param array<string, bool>|null $rules
     */
    private static function transformMarkdown(string $input, string $locale, ?string $dialect, ?array $rules): never
    {
        Pipeline::prepare($locale, $rules);
        $given = $dialect === null ? 'none' : "\"{$dialect}\"";
        throw new PolytypoException(
            PolytypoException::CODE_INVALID_DIALECT,
            "markdown mode is not implemented by this runtime (dialect given: {$given}). "
                . 'See spec/CONFORMANCE.md in github.com/polytypo/polytypo for what this runtime implements.',
        );
    }
}
