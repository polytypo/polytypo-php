<?php

declare(strict_types=1);

namespace Polytypo\Modes;

use Polytypo\PolytypoException;

/**
 * Wraps any error either mode parser produces into POLYTYPO_MALFORMED_INPUT -- no third-party
 * parser's error type is ever allowed to escape transform().
 */
final class ParseError
{
    private function __construct()
    {
    }

    public static function wrap(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (PolytypoException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PolytypoException(
                PolytypoException::CODE_MALFORMED_INPUT,
                "input did not parse: {$e->getMessage()}",
            );
        }
    }
}
