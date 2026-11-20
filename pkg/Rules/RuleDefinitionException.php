<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Rules;

use InvalidArgumentException;

/** A rule-definition error; path is relative to the rules mapping. */
final class RuleDefinitionException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $path = '')
    {
        parent::__construct($message);
    }
}
