<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

/**
 * First-class structured output of one step.
 *
 * The wrapper marks presence: a state without a `StepOutput` has no output,
 * while a state whose `StepOutput` wraps `null` carries a present JSON null.
 * Values must stay JSON-compatible (scalars, arrays, null) so every state
 * store, RPC surface, and native runtime can serialize them unchanged.
 *
 * Hosts must not route domain data through step metadata; metadata stays
 * reserved for execution bookkeeping (see docs/graphcompose.md lesson 1).
 */
final readonly class StepOutput
{
    public function __construct(
        public mixed $value = null,
    ) {
    }

    public static function from(mixed $value): self
    {
        return new self($value);
    }

    public static function null(): self
    {
        return new self(null);
    }
}
