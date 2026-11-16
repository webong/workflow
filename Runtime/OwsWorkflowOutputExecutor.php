<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

/**
 * Applies workflow-level `output.as` / export transformation after the last task.
 *
 * This executor runs as the last step (by convention) and transforms the
 * final task's output according to the `output.as` expression, producing
 * the final workflow output.
 */
final readonly class OwsWorkflowOutputExecutor implements FlowStepExecutor
{
    public function __construct(
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'workflow.output';
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        $step = $invocation->step;
        if (! $invocation->context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS workflow.output task '{$step->id}' requires an OwsWorkflowContext.");
        }
        if (OwsRouting::shouldSkip($step, $invocation->context)) {
            return StepResult::skipped('not selected by switch routing');
        }

        $outputAs = $step->metadata['ows_output_as'] ?? null;
        if (! is_string($outputAs) || $outputAs === '') {
            throw new \RuntimeException("OWS workflow.output task '{$step->id}' requires an 'ows_output_as' expression.");
        }

        // The workflow output is the last task's transformed output
        $rawOutput = $invocation->context->lastOutput();
        $transformed = OwsDataTaskPipeline::evaluate($outputAs, $rawOutput, [], $this->expressionEvaluator, $step->id);

        // Record as workflow output (export)
        $invocation->context->recordStepOutput($step->id, $transformed);

        return StepResult::completed(
            'workflow output transformed',
            ['ows_output' => $transformed, 'ows_export' => true],
            output: StepOutput::from($transformed),
        );
    }
}