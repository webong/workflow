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
 * Executes OWS `for` tasks for data-only bodies: evaluates the collection
 * (`in`), then runs the body pipeline once per item with the iteration item
 * (`each`, default `item`) and index (`$index`) bound as runtime-expression
 * arguments. Each iteration's task input is the previous iteration's output
 * (chain semantics), so bodies accumulate; the loop output is the last
 * iteration's output.
 */
final class OwsForExecutor implements FlowStepExecutor
{
    public function __construct(
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'for';
    }

    public function execute(
        FlowStepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepResult {
        if (! $context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS for task '{$step->id}' requires an OwsWorkflowContext.");
        }

        $for = is_array($step->metadata['ows_for'] ?? null) ? $step->metadata['ows_for'] : [];
        $body = is_array($step->metadata['ows_body'] ?? null) ? $step->metadata['ows_body'] : [];
        if ($body === []) {
            $context->recordStepOutput($step->id, null);
            return StepResult::completed('for task with an empty body', ['ows_output' => null]);
        }

        $rawInput = OwsRouting::resolveChainInput($step, $context);
        $taskInput = $rawInput;
        $inputExpression = $step->metadata['ows_input_expression'] ?? null;
        if (is_string($inputExpression) && $inputExpression !== '') {
            $taskInput = OwsDataTaskPipeline::evaluate($inputExpression, $rawInput, [], $this->expressionEvaluator, $step->id);
        }

        $collection = OwsDataTaskPipeline::evaluate(
            is_string($for['in'] ?? null) && $for['in'] !== '' ? $for['in'] : '.',
            $taskInput,
            [],
            $this->expressionEvaluator,
            $step->id,
        );
        if (! is_array($collection)) {
            throw new \RuntimeException("OWS for task '{$step->id}' requires a collection to iterate.");
        }

        $itemName = is_string($for['each'] ?? null) && $for['each'] !== '' ? $for['each'] : 'item';
        $output = null;
        foreach (array_values($collection) as $index => $item) {
            $vars = [$itemName => $item, 'index' => $index, 'item' => $item];
            // Chain semantics inside the loop: the first iteration sees the
            // loop's task input; later iterations see the previous output.
            $output = OwsDataTaskPipeline::run(
                $body,
                $output ?? $taskInput,
                $vars,
                $this->expressionEvaluator,
                $step->id,
            );
        }

        $context->recordStepOutput($step->id, $output);

        return StepResult::completed('iterated ' . count($collection) . ' item(s)', ['ows_output' => $output]);
    }
}