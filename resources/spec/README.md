# Vendored spec subset

This directory is a manually-synced copy of the subset of `polytypo/polytypo`'s canonical `spec/`
that this package needs at runtime: `locales/`, `fixtures/`, `rules/order.json`, `rules/dashes.md`,
`schema/`, `VERSION`, `UNICODE`. It is **not** the canonical spec — the rest of the normative prose
(`spec/rules/*.md` beyond `dashes.md`) and `validate-spec.mjs` live only in `polytypo/polytypo`.

Named `resources/spec/`, not a bare `spec/` or `vendor/spec/`: Composer itself reserves `vendor/`
at this package's own repo root for its *own* installed dependencies (PHPUnit, eris) during local
`composer install` — vendoring polytypo's spec data there would collide with that, the same class
of naming problem RSpec's own `spec/` convention forced the Ruby port to solve by using
`lib/polytypo/data/` instead of a bare `spec/`. PHP has no PHPUnit-directory collision (PHPUnit
uses `tests/`, not `spec/`), so this is the one copy, shipped as part of the package's own tree and
loaded via `file_get_contents()` relative to `__DIR__` at runtime — never a network or
external-filesystem read. Composer distributes a GitHub-sourced package as the tracked repository
content (filtered by `.gitattributes` `export-ignore`), so no build step is needed to get this
directory into what a consumer receives.

Editing a file here does not change the spec; it only drifts this copy from canonical. When
canonical's `spec/` changes, re-copy the affected files here. How this vendoring will work
long-term (submodule, per-ecosystem spec package, or something else) is an open decision tracked in
`polytypo/polytypo`'s roadmap; this is the interim, manually-synced form — the same status every
other port's vendored copy has.

## `locales/*.json` here carry no `sources`

The canonical files do, and mandatorily — `spec/schema/locale.schema.json` makes `sources`
required with `minItems: 1`, and `validate-spec.mjs` fails a locale without a citation. This copy
drops that one field, because these exact files are what ships: no rule reads the citations, and
they are 94% of the locale payload by raw bytes (193 KB of 206 KB, against 13 KB of everything the
engine actually consults). Keeping them here would put a quarter-megabyte of citation prose in
every install of this package.

So this is one field narrower than the canonical file, deliberately, and it is not drift: the
directory was always "the subset it needs" (see the paragraph above). **The citations are
evidence and they are not weakened — read them in `polytypo/polytypo`'s own `spec/locales/`, or
on the project's Locales page, which renders them from those files.** Re-syncing this directory
means copying the canonical files and dropping `sources` again.
