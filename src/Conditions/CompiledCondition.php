<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

use Closure;
use Throwable;

final readonly class CompiledCondition
{
    /** @param Closure(mixed): ConditionResult $evaluate */
    public function __construct(private Closure $evaluate) {}

    public function evaluate(mixed $input): ConditionResult
    {
        try { return ($this->evaluate)($input); }
        catch (Throwable $error) { return ConditionResult::failed($error->getMessage()); }
    }

    /** Errors fail closed; use evaluate() when diagnostics matter. */
    public function matches(mixed $input): bool { return $this->evaluate($input)->matched(); }
}
