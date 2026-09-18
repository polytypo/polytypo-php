<?php

declare(strict_types=1);

namespace Polytypo\Engine;

use Polytypo\Engine\Rules\Rules;
use Polytypo\PolytypoException;

/**
 * Resolves the locale, builds the rule plan, and runs each enabled rule in
 * spec/rules/order.json order over a code-point array, applying its edits before the next rule
 * sees it. No module-level mutable state beyond the immutable, load-once-on-first-use spec data
 * in Registry/Locale (ARCHITECTURE.md section 7).
 */
final class Pipeline
{
    private function __construct()
    {
    }

    /**
     * Defaults + opt-out overrides. $rulesOption is opt-out only (ARCHITECTURE.md section 7): it
     * may only disable a default-on rule or enable the one default-off rule ("ranges"). An
     * unknown key raises POLYTYPO_UNKNOWN_RULE.
     *
     * @param array<string, bool>|null $rulesOption
     * @return string[]
     */
    public static function resolveRulePlan(?array $rulesOption): array
    {
        Rules::registerAll();
        $defaults = Registry::ruleDefaults();
        $enabled = $defaults;
        foreach ($rulesOption ?? [] as $ruleId => $flag) {
            if (!array_key_exists($ruleId, $defaults)) {
                throw new PolytypoException(
                    PolytypoException::CODE_UNKNOWN_RULE,
                    "unknown rule id: \"{$ruleId}\"",
                );
            }
            $enabled[$ruleId] = (bool) $flag;
        }

        return array_values(array_filter(Registry::ruleOrder(), static fn (string $id): bool => $enabled[$id]));
    }

    /**
     * Builds the rule plan, then resolves the locale and loads its data -- the setup step shared
     * by the text pipeline and the span-runner (html mode). Order matters and is public, tested
     * behaviour: an unknown-rule error must win over an unknown-locale error when both are
     * present (mirrors every other port exactly).
     *
     * @param array<string, bool>|null $rulesOption
     * @return array{0: string, 1: array<string, mixed>, 2: string[]}
     */
    public static function prepare(string $locale, ?array $rulesOption): array
    {
        $plan = self::resolveRulePlan($rulesOption);
        $resolvedLocale = Locale::resolve($locale);
        $localeData = Locale::localeDataRaw($resolvedLocale);

        return [$resolvedLocale, $localeData, $plan];
    }

    /**
     * Runs each enabled rule in order.json order over cp, applying its edits before the next rule
     * sees the array. Shared by text mode and the span-runner (modes.md 3.5).
     *
     * @param int[] $cp
     * @param string[] $plan
     * @param array<string, mixed> $localeData
     * @return int[]
     */
    public static function runRules(array $cp, array $plan, array $localeData, RuleContext $ctx): array
    {
        $current = $cp;
        foreach ($plan as $ruleId) {
            $fn = Registry::rule($ruleId);
            $edits = $fn($current, $localeData, $ctx);
            if ($edits === []) {
                continue;
            }
            $current = Edits::applyEdits($current, $edits, $ruleId);
        }

        return $current;
    }

    /**
     * runRules, keeping the edits instead of discarding them (analyze.md section 1: same
     * pipeline, same order, reporting rather than applying). The origin map travels alongside the
     * array so every change comes back in input coordinates, and $filterEdits is the hook the
     * span-runner needs for modes.md 3.4's boundary filters -- text mode passes null and gets the
     * identity.
     *
     * @param int[] $cp
     * @param string[] $plan
     * @param array<string, mixed> $localeData
     * @param int[] $origin
     * @param null|callable(int[], Edit[]): Edit[] $filterEdits
     * @return \Polytypo\Change[]
     */
    public static function runRulesRecording(
        array $cp,
        array $plan,
        array $localeData,
        RuleContext $ctx,
        array $origin,
        int $inputLength,
        ?callable $filterEdits = null,
    ): array {
        $current = $cp;
        $currentOrigin = $origin;
        $changes = [];
        foreach ($plan as $ruleId) {
            $fn = Registry::rule($ruleId);
            $produced = $fn($current, $localeData, $ctx);
            $edits = $filterEdits === null ? $produced : $filterEdits($current, $produced);
            if ($edits === []) {
                continue;
            }
            array_push($changes, ...Origin::recordChanges($current, $edits, $currentOrigin, $inputLength, $ruleId));
            $currentOrigin = Origin::applyEditsToOrigin($currentOrigin, $edits);
            $current = Edits::applyEdits($current, $edits, $ruleId);
        }

        return $changes;
    }
}
