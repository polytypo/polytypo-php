<?php

declare(strict_types=1);

namespace Polytypo\Engine;

use Polytypo\PolytypoException;

/**
 * Rule order and defaults, derived from resources/spec/rules/order.json at load time -- never
 * hand-duplicated (ARCHITECTURE.md section 4.5: order.json is the single source of truth, never
 * registration order, never array-iteration order).
 */
final class Registry
{
    /** @var array<string, mixed>|null */
    private static ?array $orderData = null;

    /** @var string[]|null */
    private static ?array $ruleOrder = null;

    /** @var array<string, bool>|null */
    private static ?array $ruleDefaults = null;

    /** @var array<string, callable>|null */
    private static ?array $rules = null;

    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    private static function orderData(): array
    {
        if (self::$orderData === null) {
            $path = dirname(__DIR__, 2) . '/resources/spec/rules/order.json';
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new PolytypoException(
                    PolytypoException::CODE_MALFORMED_LOCALE_DATA,
                    "could not read spec data file \"{$path}\"",
                );
            }
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new PolytypoException(
                    PolytypoException::CODE_MALFORMED_LOCALE_DATA,
                    "spec data file \"{$path}\" did not decode to a JSON object",
                );
            }
            self::$orderData = $decoded;
        }

        return self::$orderData;
    }

    /** Rule ids in ascending pipeline order. @return string[] */
    public static function ruleOrder(): array
    {
        if (self::$ruleOrder === null) {
            $rules = self::orderData()['rules'];
            usort($rules, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            self::$ruleOrder = array_map(static fn (array $r): string => $r['id'], $rules);
        }

        return self::$ruleOrder;
    }

    /**
     * Rule id -> default enabled/disabled (only "ranges" defaults to false). order.json's
     * "default" field is the string "on"/"off", not a JSON boolean.
     *
     * @return array<string, bool>
     */
    public static function ruleDefaults(): array
    {
        if (self::$ruleDefaults === null) {
            $defaults = [];
            foreach (self::orderData()['rules'] as $r) {
                $defaults[$r['id']] = $r['default'] === 'on';
            }
            self::$ruleDefaults = $defaults;
        }

        return self::$ruleDefaults;
    }

    /**
     * Adds a rule implementation to the registry. Called once from Rules::registerAll(), which
     * every entry point invokes before running the pipeline.
     */
    public static function register(string $ruleId, callable $fn): void
    {
        self::$rules ??= [];
        self::$rules[$ruleId] = $fn;
    }

    public static function rule(string $ruleId): callable
    {
        return (self::$rules ?? [])[$ruleId];
    }

    /** @return string[] */
    public static function knownRuleIds(): array
    {
        return array_keys(self::ruleDefaults());
    }
}
