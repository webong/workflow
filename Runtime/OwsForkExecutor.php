<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

/**
 * Executes OWS `fork` tasks for data-only branches. With `compete: true` the
 * first completing branch wins and the rest are not run (deterministic for
 * data tasks — declared order); otherwise branches run in order and the last
 * branch's output flows. True parallel execution belongs to the executing
 * runtime (Temporal).
 */
final class OwsForkExecutor implements FlowStepExecutor
{
    public function __construct(
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'fork';
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        $step = $invocation->step;
        $context = $invocation->context;

        if (! $context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS fork task '{$step->id}' requires an OwsWorkflowContext.");
        }

        $fork = is_array($step->metadata['ows_fork'] ?? null) ? $step->metadata['ows_fork'] : [];
        $branches = is_array($fork['branches'] ?? null) ? $fork['branches'] : [];
        if ($branches === []) {
            $context->recordStepOutput($step->id, null);
            return StepResult::completed('fork with no branches', ['ows_output' => null], output: StepOutput::null());
        }

        $rawInput = OwsRouting::resolveChainInput($step, $context);
        $taskInput = $rawInput;
        $inputExpression = $step->metadata['ows_input_expression'] ?? null;
        if (is_string($inputExpression) && $inputExpression !== '') {
            $taskInput = OwsDataTaskPipeline::evaluate($inputExpression, $rawInput, [], $this->expressionEvaluator, $step->id);
        }

        $compete = (bool) ($fork['compete'] ?? false);
        $output = null;
        foreach ($branches as $index => $branch) {
            $output = OwsDataTaskPipeline::run(
                is_array($branch) ? $branch : [],
                $taskInput,
                [],
                $this->expressionEvaluator,
                "{$step->id}/branches/{$index}",
            );
            if ($compete) {
                break; // first completed branch wins; the rest are discarded
            }
        }

        $context->recordStepOutput($step->id, $output);

        return StepResult::completed(
            $compete ? 'competing fork: first branch won' : 'ran ' . count($branches) . ' branch(es)',
            ['ows_output' => $output],
            output: StepOutput::from($output),
        );
    }
}