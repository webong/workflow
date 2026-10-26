<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowChildRunDispatcher;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowExecutionStatus;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowChildRunRequest;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

/** Executes OWS `run.workflow` through the host's child-run dispatcher. */
final readonly class OwsWorkflowRunExecutor implements FlowStepExecutor
{
    public function __construct(
        private FlowChildRunDispatcher $children,
        private ExpressionEvaluator $expressions = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'run'
            && ($step->metadata['ows_run_type'] ?? null) === 'workflow';
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        $step = $invocation->step;
        if (! $invocation->context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS workflow task '{$step->id}' requires an OwsWorkflowContext.");
        }
        if (OwsRouting::shouldSkip($step, $invocation->context)) {
            return StepResult::skipped('not selected by switch routing');
        }

        $childFlowKey = $step->metadata['ows_run_target'] ?? null;
        $workflow = is_array($step->metadata['ows_workflow'] ?? null) ? $step->metadata['ows_workflow'] : [];
        if (! is_string($childFlowKey) || $childFlowKey === '') {
            throw new \RuntimeException("OWS workflow task '{$step->id}' has no child workflow identity.");
        }

        $rawInput = OwsRouting::resolveChainInput($step, $invocation->context);
        $input = $this->childInput($workflow['input'] ?? null, $rawInput, $step->id);
        $parentRunId = $invocation->runId;
        $childRunId = ($parentRunId ?? hash('sha256', $invocation->flowKey)).':'.$step->id.':'.$invocation->attempt;
        $await = ($step->metadata['ows_await'] ?? true) !== false;
        $receipt = $this->children->dispatch(new FlowChildRunRequest(
            childFlowKey: $childFlowKey,
            childRunId: $childRunId,
            input: $input,
            parentFlowKey: $invocation->flowKey,
            parentRunId: $parentRunId,
            parentStepId: $step->id,
            parentAttempt: $invocation->attempt,
            await: $await,
        ));

        if ($receipt->childFlowKey !== $childFlowKey || $receipt->childRunId !== $childRunId) {
            throw new \RuntimeException("OWS workflow task '{$step->id}' received a receipt for another child run.");
        }

        $metadata = [
            'child_flow_key' => $receipt->childFlowKey,
            'child_run_id' => $receipt->childRunId,
            'child_status' => $receipt->status->value,
        ];
        if ($receipt->status === FlowExecutionStatus::COMPLETED) {
            $output = $receipt->output ?? StepOutput::null();
            $invocation->context->recordStepOutput($step->id, $output->value);

            return StepResult::completed('child workflow completed', $metadata, $output);
        }

        if (! $await) {
            $output = StepOutput::from($input);
            $invocation->context->recordStepOutput($step->id, $input);

            return StepResult::completed('child workflow dispatched', $metadata, $output);
        }

        return StepResult::deferred('waiting for child workflow', metadata: $metadata);
    }

    /** @return array<string, mixed> */
    private function childInput(mixed $configured, mixed $rawInput, string $stepId): array
    {
        if ($configured === null) {
            return is_array($rawInput) ? $rawInput : ['value' => $rawInput];
        }
        if (! is_array($configured)) {
            throw new \RuntimeException("OWS workflow task '{$stepId}' input must be an object.");
        }

        $input = [];
        foreach ($configured as $name => $value) {
            if (! is_string($name)) {
                throw new \RuntimeException("OWS workflow task '{$stepId}' input must be an object.");
            }
            $input[$name] = $this->evaluateInput($value, $rawInput, $stepId);
        }

        return $input;
    }

    private function evaluateInput(mixed $value, mixed $rawInput, string $stepId): mixed
    {
        if (is_string($value)) {
            return OwsDataTaskPipeline::evaluateOrLiteralValue($value, $rawInput, [], $this->expressions, $stepId);
        }
        if (! is_array($value)) {
            return $value;
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            $resolved[$key] = $this->evaluateInput($item, $rawInput, $stepId);
        }

        return $resolved;
    }
}
