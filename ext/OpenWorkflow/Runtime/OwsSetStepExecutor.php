<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Executes OWS `set` tasks: evaluates each key's jq expression against the
 * workflow data (optionally filtered by `input.from`) and merges the results
 * into the OwsWorkflowContext so subsequent steps observe them.
 *
 * This is the adapter's execution-aware data subset (ADR 0006): set tasks are
 * pure data transformation with no side effects, so they are safe to execute
 * in-process through the core FlowRunner.
 */
final class OwsSetStepExecutor implements FlowStepExecutor
{
    public function __construct(
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'set';
    }

    public function execute(
        FlowStepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepResult {
        $set = $step->metadata['ows_set'] ?? [];
        if (! is_array($set) || $set === []) {
            return StepResult::completed('set task with no assignments', ['ows_set_data' => []]);
        }

        // Determine the data scope: workflow data, optionally filtered by
        // the task's `input.from` expression (OWS input filtering). The
        // filtered value becomes the jq `.` for the set expressions.
        $scope = $context->all();
        $inputExpression = $step->metadata['ows_input_expression'] ?? null;
        if (is_string($inputExpression) && $inputExpression !== '') {
            // `input.from` holds a bare jq expression; the filtered value
            // becomes the data scope (jq `.`) for this task's set expressions.
            $scope = $this->evaluate($inputExpression, $scope, $step->id);
        }

        $results = [];
        foreach ($set as $key => $value) {
            $results[(string) $key] = is_string($value)
                ? $this->evaluateOrLiteral($value, $scope, $step->id)
                : $value; // numbers, bools, null, arrays: literal assignment
        }

        if ($context instanceof OwsWorkflowContext) {
            $context->merge($results);
        }

        return StepResult::completed(
            "set " . count($results) . " data key(s)",
            ['ows_set_data' => $results],
        );
    }

    /**
     * OWS set values are literals unless wrapped as `${ ... }` runtime
     * expressions; plain strings (e.g. `shape: circle`) assign as-is.
     */
    private function evaluateOrLiteral(string $value, mixed $scope, string $taskId): mixed
    {
        $trimmed = trim($value);
        if (preg_match('/^\$\{(.*)\}$/s', $trimmed, $m) !== 1) {
            return $value; // literal
        }
        return $this->evaluate(trim($m[1]), $scope, $taskId, false);
    }

    /**
     * @param mixed $scope The jq input (`.`) for the expression
     */
    private function evaluate(string $expression, mixed $scope, string $taskId): mixed
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        try {
            return $this->expressionEvaluator->evaluateAgainstInput($expression, $scope);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "OWS set task '{$taskId}' failed to evaluate expression '{$expression}': {$e->getMessage()}",
                0,
                $e,
            );
        }
    }
}