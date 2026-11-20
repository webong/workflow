<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\Conditions\CompiledCondition;

/** An explicit predicate engine. Core owns composition, versioning and traversal limits. */
interface ConditionDriver
{
    public function syntax(): string;

    /**
     * Validate and compile a leaf without evaluating runtime data.
     * Errors use ConditionDefinitionException with a pointer relative to this leaf.
     * @param array<mixed> $document
     */
    public function compile(array $document): CompiledCondition;

    /** @return array<string, mixed> Leaf JSON Schema; core adds the common version property. */
    public function schema(): array;
}
