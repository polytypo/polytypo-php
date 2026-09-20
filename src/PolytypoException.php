<?php

declare(strict_types=1);

namespace Polytypo;

/**
 * One exception type carrying a stable machine code (docs/ARCHITECTURE.md section 4.6) -- the
 * same shape every other runtime uses (JS Error subclass, Go error value, Python exception, Ruby
 * StandardError), each with a `code`. Kept distinct from \Exception's own integer getCode() to
 * avoid confusing the two: this code is one of the seven CODE_* string constants below, not an
 * arbitrary integer.
 */
final class PolytypoException extends \RuntimeException
{
    // Untyped: typed class constants require PHP 8.3, and this package's floor is 8.2.
    public const CODE_UNKNOWN_LOCALE = 'POLYTYPO_UNKNOWN_LOCALE';
    public const CODE_INVALID_MODE = 'POLYTYPO_INVALID_MODE';
    public const CODE_INVALID_DIALECT = 'POLYTYPO_INVALID_DIALECT';
    public const CODE_UNKNOWN_RULE = 'POLYTYPO_UNKNOWN_RULE';
    public const CODE_MALFORMED_LOCALE_DATA = 'POLYTYPO_MALFORMED_LOCALE_DATA';
    public const CODE_RULE_CONTRACT = 'POLYTYPO_RULE_CONTRACT';
    public const CODE_MALFORMED_INPUT = 'POLYTYPO_MALFORMED_INPUT';
    // Spec 1.3.0. Deliberately general: every option added from 1.3.0 on shares this code, while
    // `mode` and `dialect` keep their own because callers branch on them.
    public const CODE_INVALID_OPTION = 'POLYTYPO_INVALID_OPTION';

    private readonly string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
