<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsRouting;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsWorkflowContext;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Executes OWS `set` tasks per the 1.0.3 data flow model:
 *
 * - raw input = the chain predecessor's output (`ows_previous`), or the
 *   workflow input for the first task;
 * - `input.from` filters the raw input into the task input (jq `.`);
 * - each `set` value is a literal or a `${ ... }` runtime expression
 *   evaluated against the task input;
 * - the task's output is the resulting map, recorded so the next task in the
 *   chain consumes it; the workflow context is not modified (no `export.as`
 *   in this subset).
 *
 * Set tasks are pure data transformation with no side effects, so they are
 * safe to execute in-process through the core FlowRunner (ADR 0006).
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
        if ($context instanceof OwsWorkflowContext && OwsRouting::shouldSkip($step, $context)) {
            return StepResult::skipped('not selected by switch routing');
        }

        $set = $step->metadata['ows_set'] ?? [];
        if (! is_array($set) || $set === []) {
            $this->recordOutput($context, $step->id, null);
            return StepResult::completed('set task with no assignments', ['ows_output' => null]);
        }

        // Raw input: the most recent executed predecessor's output, or the
        // workflow input for the first task in the chain. Routing-skipped
        // steps are transparent.
        $rawInput = $context instanceof OwsWorkflowContext
            ? OwsRouting::resolveChainInput($step, $context)
            : $context->all();

        // Task input: `input.from` filters the raw input (jq `.`).
        $taskInput = $rawInput;
        $inputExpression = $step->metadata['ows_input_expression'] ?? null;
        if (is_string($inputExpression) && $inputExpression !== '') {
            $taskInput = $this->evaluate($inputExpression, $rawInput, $step->id);
        }

        $output = [];
        foreach ($set as $key => $value) {
            $output[(string) $key] = is_string($value)
                ? $this->evaluateOrLiteral($value, $taskInput, $step->id)
                : $value; // numbers, bools, null, arrays: literal assignment
        }

        $this->recordOutput($context, $step->id, $output);

        return StepResult::completed(
            "set " . count($output) . " data key(s)",
            ['ows_output' => $output],
        );
    }

    /**
     * OWS set values are literals unless wrapped as `${ ... }` runtime
     * expressions; plain strings (e.g. `shape: circle`) assign as-is.
     */
    private function evaluateOrLiteral(string $value, mixed $taskInput, string $taskId): mixed
    {
        $trimmed = trim($value);
        if (preg_match('/^\$\{(.*)\}$/s', $trimmed, $m) !== 1) {
            return $value; // literal
        }
        return $this->evaluate(trim($m[1]), $taskInput, $taskId);
    }

    /**
     * @param mixed $input The jq input (`.`) for the expression
     */
    private function evaluate(string $expression, mixed $input, string $taskId): mixed
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        try {
            return $this->expressionEvaluator->evaluateAgainstInput($expression, $input);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "OWS set task '{$taskId}' failed to evaluate expression '{$expression}': {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    private function recordOutput(FlowContext $context, string $taskId, mixed $output): void
    {
        if ($context instanceof OwsWorkflowContext) {
            $context->recordStepOutput($taskId, $output);
        }
    }
}