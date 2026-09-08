<p align="center">
  <img src="https://raw.githubusercontent.com/polytypo/polytypo/main/brand/logo/polytypo-lockup-stacked.svg" alt="polytypo" width="260">
</p>

<h1 align="center">polytypo</h1>

<p align="center">
  <a href="https://packagist.org/packages/polytypo/polytypo"><img src="https://img.shields.io/packagist/v/polytypo/polytypo.svg" alt="Packagist version"></a>
  <a href="https://github.com/polytypo/polytypo-php/actions/workflows/ci.yml"><img src="https://github.com/polytypo/polytypo-php/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License: MIT"></a>
</p>

<p align="center">
  Locale-correct quotes, dashes, ellipses, apostrophes, symbols and no-break spaces —<br>
  one portable spec, designed for byte-identical output across runtimes.
</p>

<p align="center">
  <strong>Try it live, no install: <a href="https://polytypo.dev/">polytypo.dev</a></strong>
</p>

This is the PHP implementation. The full spec — all locales, all rules, worked examples in each —
lives in [polytypo/polytypo](https://github.com/polytypo/polytypo). This runtime supports the
`text` and `html` modes fully. `markdown` mode is **not implemented** for either dialect in this
runtime (see [Markdown mode](#markdown-mode)).

## Install

```sh
composer require polytypo/polytypo
```

## Usage

```php
use Polytypo\Polytypo;

Polytypo::transform('She said, "it\'s fine" -- but I wasn\'t sure...', 'en-US');
// => "She said, “it’s fine”—but I wasn’t sure…"
```

Same input, one locale changed — quotes, dash spacing and all follow the target locale, not a
single hardcoded style:

```php
Polytypo::transform('Sie sagte: "Alles gut" -- aber ich war mir nicht sicher...', 'de-DE');
// => "Sie sagte: „Alles gut“ – aber ich war mir nicht sicher…"
```

HTML is a first-class mode, not an afterthought — tags, attributes and skip-listed elements
(`code`, `pre`, `script`, ...) are left alone; only text content is touched:

```php
Polytypo::transform('<a title="test... wait">Wait... she said "go on."</a>', 'en-US', mode: 'html');
// => "<a title=\"test... wait\">Wait… she said “go on.”</a>"
```

`$locale` has no default anywhere and must always be passed explicitly — there is no silent
fallback to English. `$mode` defaults to `"text"` if omitted. Every parameter after `$locale`
accepts PHP named arguments:

```php
Polytypo::transform($input, 'fr', mode: 'html', rules: ['ranges' => true]);
```

### Errors

Every error `Polytypo::transform` raises is a `Polytypo\PolytypoException` carrying one of seven
stable codes — check `getErrorCode()`, not the message text, which is English and informative but
not part of the contract:

```php
try {
    Polytypo::transform('x', 'xx-ZZ');
} catch (\Polytypo\PolytypoException $e) {
    $e->getErrorCode(); // => "POLYTYPO_UNKNOWN_LOCALE"
}
```

## Markdown mode

`markdown` mode requires a `dialect`, exactly as the spec requires (no default, detection is
forbidden) — but **no dialect is implemented by this runtime**, so `mode: "markdown"` always
raises `PolytypoException` with `POLYTYPO_INVALID_DIALECT`, whatever `dialect` value is given.
This is a real gap, not an oversight: `league/commonmark` (the standard PHP CommonMark/GFM
library) gives no position data at all on inline text nodes — only a line number on block nodes —
so it cannot support the raw-extent span reconstruction this mode requires, and no other
maintained PHP CommonMark/GFM library with that property was found. See
[spec/CONFORMANCE.md](https://github.com/polytypo/polytypo/blob/main/spec/CONFORMANCE.md) in the
canonical repository for the exact, current claim.

## Thread safety

`Polytypo::transform` is a pure static method with no static mutable state beyond
memoized-once, never-mutated spec data: safe to call from any context with no external
synchronization.

## Licence

MIT. See [LICENSE](LICENSE).
