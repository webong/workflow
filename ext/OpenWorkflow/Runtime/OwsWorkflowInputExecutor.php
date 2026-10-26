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
 * Applies workflow-level `input.from` transformation before the first task runs.
 *
 * This executor runs as the first step (by convention) and transforms the
 * raw workflow input according to the `input.from` expression, making the
 * result available as the workflow input for the first task.
 */
final readonly class OwsWorkflowInputExecutor implements FlowStepExecutor
{
    public function __construct(
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'workflow.input';
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        $step = $invocation->step;
        if (! $invocation->context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS workflow.input task '{$step->id}' requires an OwsWorkflowContext.");
        }
        if (OwsRouting::shouldSkip($step, $invocation->context)) {
            return StepResult::skipped('not selected by switch routing');
        }

        $inputFrom = $step->metadata['ows_input_from'] ?? null;
        if (! is_string($inputFrom) || $inputFrom === '') {
            throw new \RuntimeException("OWS workflow.input task '{$step->id}' requires an 'ows_input_from' expression.");
        }

        $rawInput = OwsRouting::resolveChainInput($step, $invocation->context);
        $transformed = OwsDataTaskPipeline::evaluate($inputFrom, $rawInput, [], $this->expressionEvaluator, $step->id);

        $invocation->context->recordStepOutput($step->id, $transformed);

        return StepResult::completed(
            'workflow input transformed',
            ['ows_output' => $transformed],
            output: StepOutput::from($transformed),
        );
    }
}