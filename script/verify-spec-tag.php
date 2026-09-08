<?php

/**
 * Two-tag release contract, existence-only variant (docs/ROADMAP.md M5,
 * docs/REPOSITORY_SPLIT_AND_SPEC_SYNC.md section 4.4, canonical repo).
 *
 * An operator creates and pushes canonical spec tag spec-v<VERSION> (VERSION from
 * resources/spec/VERSION) to polytypo/polytypo before pushing this repo's own release tag
 * v<X.Y.Z> (only the latter triggers .github/workflows/release.yml). This proves that tag exists
 * in the canonical repository -- existence only, not commit-SHA equality: this repository was
 * never part of polytypo/polytypo's git history (it is a from-spec port, not a filtered
 * extraction), so its commits share no ancestry with the canonical repository's.
 *
 * Reads the GitHub REST API unauthenticated (polytypo/polytypo is public), never a local git
 * operation against the canonical repo. Exits non-zero with a GitHub Actions ::error:: annotation
 * on any failure.
 */

declare(strict_types=1);

const CANONICAL_REPO = 'polytypo/polytypo';
const STRICT_SEMVER = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/';

function failWith(string $message): never
{
    echo "::error::{$message}", PHP_EOL;
    exit(1);
}

function parseStrictSpecVersion(string $raw): string
{
    $version = trim($raw);
    if ($version === '') {
        failWith('resources/spec/VERSION is empty (after trimming whitespace).');
    }
    if (preg_match(STRICT_SEMVER, $version) !== 1) {
        failWith(
            "resources/spec/VERSION content \"{$version}\" is not a strict MAJOR.MINOR.PATCH "
            . 'release version -- pre-release suffixes, build-metadata suffixes, leading zeros, '
            . 'and any other form are rejected by this project\'s spec-tag policy.',
        );
    }

    return $version;
}

function canonicalTagExists(string $tagName): bool
{
    $url = 'https://api.github.com/repos/' . CANONICAL_REPO . '/git/refs/tags/' . $tagName;
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/vnd.github+json\r\nUser-Agent: polytypo-php-release\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
        failWith("Could not determine HTTP status resolving tag \"{$tagName}\" in " . CANONICAL_REPO . '.');
    }
    $status = (int) $m[1];

    if ($status === 200) {
        return true;
    }
    if ($status === 404) {
        return false;
    }

    failWith("GitHub API returned {$status} resolving tag \"{$tagName}\" in " . CANONICAL_REPO . '.');
}

$root = dirname(__DIR__);
$specVersionRaw = file_get_contents($root . '/resources/spec/VERSION');
if ($specVersionRaw === false) {
    failWith('could not read resources/spec/VERSION.');
}
$specVersion = parseStrictSpecVersion($specVersionRaw);

$tagName = "spec-v{$specVersion}";
echo "resources/spec/VERSION:      {$specVersion}", PHP_EOL;
echo "expected canonical spec tag: {$tagName}", PHP_EOL;

if (!canonicalTagExists($tagName)) {
    failWith(
        "Tag \"{$tagName}\" does not exist in " . CANONICAL_REPO . '. It must be created and '
        . 'pushed by an operator to ' . CANONICAL_REPO . ' before this repository\'s release tag.',
    );
}

echo "ok: canonical spec tag \"{$tagName}\" exists in " . CANONICAL_REPO . '.', PHP_EOL;
