<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

use InvalidArgumentException;

/** Configuration error located by an RFC 6901 pointer relative to the condition. */
final class ConditionDefinitionException extends InvalidArgumentException
{
    public function __construct(public readonly string $diagnosticCode, string $message, public readonly string $path = '')
    {
        parent::__construct($message);
    }
}
