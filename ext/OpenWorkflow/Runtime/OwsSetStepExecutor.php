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

        if (is_array($step->metadata['ows_catch'] ?? null)) {
            return $this->executeCatch($step, $context);
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
     * Catch handler: skips when the try body completed cleanly, runs with the
     * error bound when the fault matches the filters, and faults the workflow
     * when the error is uncaught.
     */
    private function executeCatch(FlowStepDefinition $step, FlowContext $context): StepResult
    {
        if (! $context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS catch task '{$step->id}' requires an OwsWorkflowContext.");
        }

        $catch = $step->metadata['ows_catch'];
        $tryId = is_string($catch['try_id'] ?? null) ? $catch['try_id'] : '';
        $fault = $context->faultFor($tryId);

        if ($fault === null) {
            return StepResult::skipped('try completed without a catchable fault');
        }

        if (! $this->faultMatches($fault, is_array($catch['filters'] ?? null) ? $catch['filters'] : [])) {
            // Uncaught: the workflow faults with the original error.
            return StepResult::failed(
                error: is_string($fault['title'] ?? null) ? $fault['title'] : 'Uncaught error',
                retriable: false,
                metadata: ['ows_error' => $fault],
            );
        }

        // Handle: run the set assignments with the error bound to the
        // catch's variable name (`as: err`).
        $as = is_string($catch['as'] ?? null) && $catch['as'] !== '' ? $catch['as'] : 'error';
        $rawInput = OwsRouting::resolveChainInput($step, $context);
        $set = is_array($step->metadata['ows_set'] ?? null) ? $step->metadata['ows_set'] : [];

        $output = [];
        foreach ($set as $key => $value) {
            $output[(string) $key] = is_string($value)
                ? OwsDataTaskPipeline::evaluateOrLiteralValue($value, $rawInput, [$as => $fault], $this->expressionEvaluator, $step->id)
                : $value;
        }

        $context->consumeFault($tryId);
        $context->recordStepOutput($step->id, $output);

        return StepResult::completed('caught error handled', ['ows_output' => $output]);
    }

    /**
     * @param array<string, mixed> $fault
     * @param array<string, mixed> $filters
     */
    private function faultMatches(array $fault, array $filters): bool
    {
        $type = $filters['type'] ?? null;
        if (is_string($type) && ($fault['type'] ?? null) !== $type) {
            return false;
        }
        $status = $filters['status'] ?? null;
        if (is_int($status) && (int) ($fault['status'] ?? -1) !== $status) {
            return false;
        }
        return true;
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
            return OwsDataTaskPipeline::evaluate($expression, $input, [], $this->expressionEvaluator, $taskId);
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