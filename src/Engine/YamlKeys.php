<?php

declare(strict_types=1);

namespace Polytypo\Engine;

use Polytypo\PolytypoException;

/**
 * modes.md 3.8.2: `yaml` mode's `keys` option, resolved once at the call boundary.
 *
 * It has NO DEFAULT, for the reason `dialect` has none. YAML is a data format with islands of
 * prose in it, and nothing in its syntax marks them -- `description` holds a sentence and `run`
 * holds a shell script, spelled identically -- so a default would be a guess about the schema
 * above the document. An EMPTY LIST IS LEGAL: "process nothing" is a choice a caller may make,
 * not an error.
 *
 * Checked after `locale`, last of the option checks, alongside `dialect` (ARCHITECTURE.md
 * section 7). The two never both apply, since each belongs to a different mode.
 */
final class YamlKeys
{
    private function __construct()
    {
    }

    /**
     * The parameter is deliberately typed as an untrusted array rather than list<string>: this is
     * a boundary check on a caller's input (ARCHITECTURE.md section 7), and a PHPDoc promise is
     * not one. A plain-PHP caller can pass anything.
     *
     * @param array<mixed>|null $keys
     * @return array<string, true> the key set, for O(1) membership
     */
    public static function resolve(?array $keys): array
    {
        if ($keys === null) {
            throw new PolytypoException(
                PolytypoException::CODE_INVALID_OPTION,
                '"keys" is required when mode is "yaml" and must be a list of strings '
                . '(modes.md 3.8.2); an empty list is legal and processes nothing',
            );
        }

        $set = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new PolytypoException(
                    PolytypoException::CODE_INVALID_OPTION,
                    '"keys" must contain only strings',
                );
            }
            $set[$key] = true;
        }

        return $set;
    }
}
