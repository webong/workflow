<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowEventSink;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

/**
 * Emits OWS lifecycle events via the host's FlowEventSink.
 *
 * This executor does not execute a task; instead it records a lifecycle
 * event configuration that the host translates into explicit delivery
 * requests. The event is idempotent and observable.
 */
final readonly class OwsLifecycleExecutor implements FlowStepExecutor
{
    public function __construct(
        private readonly FlowEventSink $eventSink,
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'lifecycle';
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        $step = $invocation->step;
        if (! $invocation->context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS lifecycle task '{$step->id}' requires an OwsWorkflowContext.");
        }
        if (OwsRouting::shouldSkip($step, $invocation->context)) {
            return StepResult::skipped('not selected by switch routing');
        }

        $lifecycle = $step->metadata['ows_lifecycle'] ?? null;
        if (! is_array($lifecycle)) {
            throw new \RuntimeException("OWS lifecycle task '{$step->id}' requires a lifecycle configuration.");
        }

        $eventType = $lifecycle['event'] ?? null;
        if (! is_string($eventType) || $eventType === '') {
            throw new \RuntimeException("OWS lifecycle task '{$step->id}' requires an 'event' type.");
        }

        $condition = $lifecycle['when'] ?? null;
        $dataExpr = $lifecycle['data'] ?? null;

        // Evaluate condition if present
        $rawInput = OwsRouting::resolveChainInput($step, $invocation->context);
        if ($condition !== null && $condition !== '') {
            try {
                $matches = OwsDataTaskPipeline::truthy(
                    OwsDataTaskPipeline::evaluate($condition, $rawInput, [], $this->expressionEvaluator, $step->id)
                );
                if (! $matches) {
                    return StepResult::skipped("condition not met for lifecycle event '{$eventType}'");
                }
            } catch (\Throwable) {
                return StepResult::skipped("condition evaluation failed for lifecycle event '{$eventType}'");
            }
        }

        // Evaluate data expression
        $data = [];
        if ($dataExpr !== null && $dataExpr !== '') {
            $data = OwsDataTaskPipeline::evaluate($dataExpr, $rawInput, [], $this->expressionEvaluator, $step->id);
        }

        // Emit via host event sink (idempotent, observable)
        $this->eventSink->record([
            'type' => $eventType,
            'source' => $invocation->flowKey,
            'subject' => $invocation->runId,
            'time' => date(DATE_ATOM),
            'data' => $data,
        ]);

        $output = ['event' => $eventType, 'data' => $data];
        $invocation->context->recordStepOutput($step->id, $output);

        return StepResult::completed(
            "lifecycle event '{$eventType}' emitted",
            ['ows_output' => $output, 'ows_lifecycle_event' => $eventType],
            output: StepOutput::from($output),
        );
    }
}