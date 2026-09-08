<?php

declare(strict_types=1);

namespace Polytypo\Engine\Rules;

use Polytypo\Engine\Registry;

/**
 * Registers every rule implementation with the Registry, once. PHP's autoloading is lazy (unlike
 * Ruby's require-time self-registration or Go's init()), so this explicit, idempotent
 * registration step exists to be called once before the pipeline runs -- Pipeline::resolveRulePlan
 * calls it.
 */
final class Rules
{
    private static bool $registered = false;

    private function __construct()
    {
    }

    public static function registerAll(): void
    {
        if (self::$registered) {
            return;
        }

        // Closures, not [ClassName::class, 'scan'] array-callables: PHP's `callable` type
        // eagerly validates an array-callable's class exists at the moment it's passed, which
        // would make registerAll() fail the instant one rule class was missing (a real problem
        // mid-port, with rules landing from parallel agents at different times) -- a closure
        // defers symbol resolution until actually invoked, mirroring Ruby's own registration
        // style (`->(cp, locale_data, ctx) { Rules::Quotes.scan(cp, locale_data, ctx) }`).
        Registry::register('spaces', static fn (array $cp, array $ld, $ctx): array => SpacesRule::scan($cp, $ld, $ctx));
        Registry::register('ellipsis', static fn (array $cp, array $ld, $ctx): array => EllipsisRule::scan($cp, $ld, $ctx));
        Registry::register('ranges', static fn (array $cp, array $ld, $ctx): array => RangesRule::scan($cp, $ld, $ctx));
        Registry::register('dashes', static fn (array $cp, array $ld, $ctx): array => DashesRule::scan($cp, $ld, $ctx));
        Registry::register('hyphen', static fn (array $cp, array $ld, $ctx): array => HyphenRule::scan($cp, $ld, $ctx));
        Registry::register('quotes', static fn (array $cp, array $ld, $ctx): array => QuotesRule::scan($cp, $ld, $ctx));
        Registry::register('apostrophe', static fn (array $cp, array $ld, $ctx): array => ApostropheRule::scan($cp, $ld, $ctx));
        Registry::register('symbols', static fn (array $cp, array $ld, $ctx): array => SymbolsRule::scan($cp, $ld, $ctx));
        Registry::register('nbsp', static fn (array $cp, array $ld, $ctx): array => NbspRule::scan($cp, $ld, $ctx));

        self::$registered = true;
    }
}
