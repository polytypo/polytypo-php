#!/usr/bin/env bash
set -euo pipefail

# Builds the actual distributable archive (git archive, which -- like `composer archive` for a
# VCS-based package -- respects .gitattributes export-ignore) and installs it into a scratch
# directory, then exercises every implemented mode from there. A composer-install-in-place run
# against the working tree (every other test in this suite) cannot catch an export-ignore mistake
# that silently excludes resources/spec/ from what a consumer actually receives. Do not trust the
# working tree as proof the *distributed* package is correct.

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

archive="$tmp/polytypo.tar"
git -C "$root" archive --format=tar -o "$archive" HEAD

extracted="$tmp/extracted"
mkdir -p "$extracted"
tar -xf "$archive" -C "$extracted"

if [ ! -e "$extracted/resources/spec/locales/en-US.json" ]; then
    echo "packaging smoke test FAILED: resources/spec/locales/en-US.json missing from the archived package" >&2
    exit 1
fi
if [ -e "$extracted/tests" ]; then
    echo "packaging smoke test FAILED: tests/ was NOT excluded from the archived package (export-ignore broken)" >&2
    exit 1
fi

(cd "$extracted" && composer install --no-interaction --no-dev --prefer-dist --quiet)

cat > "$tmp/probe.php" <<'PHP'
<?php
require $argv[1] . '/vendor/autoload.php';

use Polytypo\Polytypo;

$outText = Polytypo::transform('She said, "hi".', 'en-US');
if ($outText !== 'She said, “hi”.') {
    fwrite(STDERR, "text mode failed: " . var_export($outText, true) . "\n");
    exit(1);
}

$outHtml = Polytypo::transform('<p>"x"</p>', 'en-US', mode: 'html');
if (!str_contains($outHtml, '“x”')) {
    fwrite(STDERR, "html mode failed: " . var_export($outHtml, true) . "\n");
    exit(1);
}

try {
    Polytypo::transform('x', 'en-US', mode: 'markdown', dialect: 'commonmark');
    fwrite(STDERR, "expected markdown mode to raise POLYTYPO_INVALID_DIALECT\n");
    exit(1);
} catch (\Polytypo\PolytypoException $e) {
    if ($e->getErrorCode() !== \Polytypo\PolytypoException::CODE_INVALID_DIALECT) {
        fwrite(STDERR, "wrong error code for markdown mode: " . $e->getErrorCode() . "\n");
        exit(1);
    }
}

echo "ALL_OK\n";
PHP

php "$tmp/probe.php" "$extracted"
