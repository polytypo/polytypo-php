<?php

declare(strict_types=1);

namespace Polytypo;

use Polytypo\Engine\Codepoints;
use Polytypo\Engine\NarrowTarget;
use Polytypo\Engine\YamlKeys;
use Polytypo\Engine\Pipeline;
use Polytypo\Engine\RuleContext;
use Polytypo\Modes\Html;
use Polytypo\Modes\Yaml;
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
     * "html", "markdown" or "yaml". $dialect is required iff $mode is "markdown" -- but no
     * markdown dialect is implemented by this runtime (see below), so any markdown call throws
     * CODE_INVALID_DIALECT. $keys is required iff $mode is "yaml" and names the mapping keys
     * whose scalar values are prose (modes.md 3.8.2); it has no default, because nothing in
     * YAML's syntax separates "description:" from "run:". $rules is an opt-out array keyed by
     * rule id.
     *
     * Pure: no I/O, no environment, no clock, no globals, no static mutable state beyond
     * memoized-once, never-mutated-after spec data -- reentrant and safe to call from any
     * context (ARCHITECTURE.md section 7).
     *
     * @param array<string, bool>|null $rules
     * @param list<string>|null $keys
     */
    public static function transform(
        string $input,
        string $locale,
        string $mode = 'text',
        ?string $dialect = null,
        ?array $rules = null,
        ?string $narrowNbsp = null,
        ?array $keys = null,
    ): string {
        $resolvedMode = self::resolveMode($mode);
        $narrowTarget = NarrowTarget::resolve($narrowNbsp);

        return match ($resolvedMode) {
            'text' => self::transformText($input, $locale, $dialect, $rules, $narrowTarget),
            'html' => self::transformHtml($input, $locale, $dialect, $rules, $narrowTarget),
            'yaml' => self::transformYaml($input, $locale, $dialect, $keys, $rules, $narrowTarget),
            // 'markdown': no dialect is implemented by this runtime (verified this session:
            // league/commonmark gives no position data at all on inline text nodes, and no other
            // maintained PHP CommonMark/GFM library with the needed raw-extent span property
            // exists -- see docs/ROADMAP.md). Rules and locale are still validated first, via
            // transformMarkdown's own call to Pipeline::prepare, to keep error-precedence order
            // identical to every other runtime even on a call that will ultimately fail.
            default => self::transformMarkdown($input, $locale, $dialect, $rules),
        };
    }

    /**
     * Runs the same pipeline as transform() and reports what it would do instead of doing it
     * (spec/rules/analyze.md). Offsets are code-point offsets into $input in every mode -- into
     * the document, in "html" mode, not into a span.
     *
     * What it guarantees: the list is empty exactly when transform() would return the input
     * unchanged, every ruleId was enabled for the call, and every offset is inside the input.
     * What it does not: the list is a report, not a patch -- two rules may touch the same
     * original range, so replaying it is not guaranteed to reproduce transform()'s output. Call
     * transform() for the text (analyze.md sections 4 and 5).
     *
     * Pure on the same terms as transform().
     *
     * @param array<string, bool>|null $rules
     * @param list<string>|null $keys
     * @return Change[]
     */
    public static function analyze(
        string $input,
        string $locale,
        string $mode = 'text',
        ?string $dialect = null,
        ?array $rules = null,
        ?string $narrowNbsp = null,
        ?array $keys = null,
    ): array {
        $resolvedMode = self::resolveMode($mode);
        $narrowTarget = NarrowTarget::resolve($narrowNbsp);

        return match ($resolvedMode) {
            'text' => self::analyzeText($input, $locale, $dialect, $rules, $narrowTarget),
            'html' => self::analyzeHtml($input, $locale, $dialect, $rules, $narrowTarget),
            'yaml' => self::analyzeYaml($input, $locale, $dialect, $keys, $rules, $narrowTarget),
            // 'markdown': not implemented by this runtime, exactly as for transform() -- A1
            // requires analyze() to reject what transform() rejects, with the same code.
            default => self::transformMarkdown($input, $locale, $dialect, $rules),
        };
    }

    private static function resolveMode(string $mode): string
    {
        return match ($mode) {
            'text' => 'text',
            'html', 'markdown', 'yaml' => $mode,
            default => throw new PolytypoException(
                PolytypoException::CODE_INVALID_MODE,
                "unknown mode \"{$mode}\". Expected \"text\", \"html\", \"markdown\" or \"yaml\"",
            ),
        };
    }

    /** @param array<string, bool>|null $rules */
    private static function transformText(
        string $input,
        string $locale,
        ?string $dialect,
        ?array $rules,
        int $narrowTarget,
    ): string {
        if ($dialect !== null) {
            throw new PolytypoException(
                PolytypoException::CODE_INVALID_DIALECT,
                '"dialect" is only valid when mode is "markdown"',
            );
        }
        [, $localeData, $plan] = Pipeline::prepare($locale, $rules);
        $cp = Codepoints::toCodepoints($input);
        $ctx = new RuleContext(mode: 'text', dialect: null, locale: $locale, narrowTarget: $narrowTarget);
        $result = Pipeline::runRules($cp, $plan, $localeData, $ctx);

        return Codepoints::fromCodepoints($result);
    }

    /** @param array<string, bool>|null $rules */
    private static function transformHtml(
        string $input,
        string $locale,
        ?string $dialect,
        ?array $rules,
        int $narrowTarget,
    ): string {
        if ($dialect !== null) {
            throw new PolytypoException(
                PolytypoException::CODE_INVALID_DIALECT,
                '"dialect" is only valid when mode is "markdown"',
            );
        }
        [$resolvedLocale, $localeData, $plan] = Pipeline::prepare($locale, $rules);
        $spans = Html::htmlSpans($input);
        $ctx = new RuleContext(mode: 'html', dialect: null, locale: $resolvedLocale, narrowTarget: $narrowTarget);
        $cp = Codepoints::toCodepoints($input);

        return Runner::runOverSpans($cp, $spans, $plan, $localeData, $ctx);
    }

    /**
     * "yaml" mode: the only pipeline here with no parser dependency at all -- span selection is
     * the specified scan of modes.md 3.8, not a library. This runtime is half the reason it is
     * specified rather than delegated: symfony/yaml reports no positions at all, and the
     * round-trip guarantee needs them. There is likewise no CODE_MALFORMED_INPUT counterpart --
     * with no declared grammar to violate, a file that is not YAML yields few spans or none and
     * comes back byte for byte (modes.md 3.8.3). The only throw this mode adds is $keys, which is
     * about the call and not the input.
     *
     * @param list<string>|null $keys
     * @param array<string, bool>|null $rules
     */
    private static function transformYaml(
        string $input,
        string $locale,
        ?string $dialect,
        ?array $keys,
        ?array $rules,
        int $narrowTarget,
    ): string {
        if ($dialect !== null) {
            throw new PolytypoException(
                PolytypoException::CODE_INVALID_DIALECT,
                '"dialect" is only valid when mode is "markdown"',
            );
        }
        [$resolvedLocale, $localeData, $plan] = Pipeline::prepare($locale, $rules);
        $resolvedKeys = YamlKeys::resolve($keys);
        $spans = Yaml::yamlSpans($input, $resolvedKeys);
        $ctx = new RuleContext(mode: 'yaml', dialect: null, locale: $resolvedLocale, narrowTarget: $narrowTarget);
        $cp = Codepoints::toCodepoints($input);

        return Runner::runOverSpans($cp, $spans, $plan, $localeData, $ctx);
    }

    /**
     * analyze.md section 1, "yaml" mode: offsets are into the document, not into a span
     * (analyze.md section 6).
     *
     * @param list<string>|null $keys
     * @param array<string, bool>|null $rules
     * @return Change[]
     */
    private static function analyzeYaml(
        string $input,
        string $locale,
        ?string $dialect,
        ?array $keys,
        ?array $rules,
        int $narrowTarget,
    ): array {
        if ($dialect !== null) {
            throw new PolytypoException(
                PolytypoException::CODE_INVALID_DIALECT,
                '"dialect" is only valid when mode is "markdown"',
            );
        }
        [$resolvedLocale, $localeData, $plan] = Pipeline::prepare($locale, $rules);
        $resolvedKeys = YamlKeys::resolve($keys);
        $spans = Yaml::yamlSpans($input, $resolvedKeys);
        $ctx = new RuleContext(mode: 'yaml', dialect: null, locale: $resolvedLocale, narrowTarget: $narrowTarget);

        return Runner::analyzeOverSpans(Codepoints::toCodepoints($input), $spans, $plan, $localeData, $ctx);
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

    /**
     * @param array<string, bool>|null $rules
     * @return Change[]
     */
    private static function analyzeText(
        string $input,
        string $locale,
        ?string $dialect,
        ?array $rules,
        int $narrowTarget,
    ): array {
        if ($dialect !== null) {
            throw new PolytypoException(
                PolytypoException::CODE_INVALID_DIALECT,
                '"dialect" is only valid when mode is "markdown"',
            );
        }
        [, $localeData, $plan] = Pipeline::prepare($locale, $rules);
        $cp = Codepoints::toCodepoints($input);
        $ctx = new RuleContext(mode: 'text', dialect: null, locale: $locale, narrowTarget: $narrowTarget);

        return Pipeline::runRulesRecording($cp, $plan, $localeData, $ctx, array_keys($cp), count($cp));
    }

    /**
     * @param array<string, bool>|null $rules
     * @return Change[]
     */
    private static function analyzeHtml(
        string $input,
        string $locale,
        ?string $dialect,
        ?array $rules,
        int $narrowTarget,
    ): array {
        if ($dialect !== null) {
            throw new PolytypoException(
                PolytypoException::CODE_INVALID_DIALECT,
                '"dialect" is only valid when mode is "markdown"',
            );
        }
        [$resolvedLocale, $localeData, $plan] = Pipeline::prepare($locale, $rules);
        $spans = Html::htmlSpans($input);
        $ctx = new RuleContext(mode: 'html', dialect: null, locale: $resolvedLocale, narrowTarget: $narrowTarget);

        return Runner::analyzeOverSpans(Codepoints::toCodepoints($input), $spans, $plan, $localeData, $ctx);
    }
}
