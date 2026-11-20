<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

use Webong\WorkFlow\Contracts\FlowConditionValidator;
use Webong\WorkFlow\ValueObjects\FlowDiagnostic;

/** Shares runtime compilation semantics, without evaluating predicates or run data. */
final readonly class ConditionAuthoringValidator implements FlowConditionValidator
{
    public function __construct(private ConditionCompiler $compiler = new ConditionCompiler()) {}

    public function validate(mixed $condition, string $path = '', ?string $stepId = null): array
    {
        if (! is_array($condition)) {
            return [new FlowDiagnostic('condition_not_object', 'A condition must be a document.', path: $path, stepId: $stepId)];
        }
        try {
            $this->compiler->compile($condition);
        } catch (ConditionDefinitionException $error) {
            return [new FlowDiagnostic($error->diagnosticCode, $error->getMessage(), path: $path.$error->path, stepId: $stepId)];
        }
        return [];
    }

    public function schema(): array
    {
        return $this->compiler->schema();
    }
}
