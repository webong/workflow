<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use RuntimeException;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowEventSink;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowEventType;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepState;
use Webong\WorkFlow\Services\NullFlowEventSink;

final class FlowRunner
{
    public function __construct(
        private readonly FlowEvaluator $evaluator = new FlowEvaluator(),
        private readonly FlowEventSink $events = new NullFlowEventSink(),
        private readonly FlowStepExecution $execution = new FlowStepExecution(),
        private readonly FlowInputResolver $inputs = new FlowInputResolver(),
        private readonly FlowStepExecutorResolver $executorResolver = new FlowStepExecutorResolver(),
        private readonly FlowReadySet $ready = new FlowReadySet(),
    )
    {
    }

    /**
     * Executes each eligible step at most once, dependencies first. The host can
     * queue this operation or persist the returned state through its adapter.
     *
     * @param iterable<FlowStepExecutor> $executors
     */
    public function run(
        FlowDefinition $definition,
        FlowState $state,
        FlowContext $context,
        iterable $executors,
    ): FlowState {
        $state->run?->assertDefinition($definition);
        if (in_array($state->status, [\Webong\WorkFlow\Enums\FlowStatus::CANCELLED, \Webong\WorkFlow\Enums\FlowStatus::COMPLETED], true)) {
            return $state;
        }
        $executors = $this->executorResolver->normalize($executors);
        $this->events->record(new \Webong\WorkFlow\ValueObjects\FlowEvent(FlowEventType::STARTED, $definition->key));

        foreach ($definition->executionSteps() as $step) {
            if (! $step instanceof \Webong\WorkFlow\ValueObjects\FlowStepDefinition) {
                throw new \InvalidArgumentException('Flow steps must be step definitions.');
            }
            $previous = $state->steps[$step->id] ?? new StepState();
            if (! $previous instanceof StepState) {
                throw new \InvalidArgumentException('Stored steps must be step states.');
            }

            if (! $this->ready->isReady($step, $state)) {
                continue;
            }

            $executor = $this->executorResolver->resolve($step, $executors);

            if (! $executor instanceof FlowStepExecutor) {
                throw new RuntimeException("No executor registered for flow step '{$step->id}'.");
            }

            $this->events->record(new \Webong\WorkFlow\ValueObjects\FlowEvent(FlowEventType::STEP_STARTED, $definition->key, $step->id));
            // Bindings resolve before the executor is chosen to run: a
            // required unresolved binding fails closed without invoking it.
            $input = $this->inputs->resolve($this->events, $definition->key, $step, $state, $context);
            $stepState = $this->execution->execute($executor, new FlowStepInvocation(
                step: $step,
                input: $input,
                context: $context,
                previous: $previous,
                flowKey: $definition->key,
                runId: $state->run?->id,
                attempt: $previous->attempts + 1,
            ));
            $state = $state->withStep($step->id, $stepState);
            $this->events->record(new \Webong\WorkFlow\ValueObjects\FlowEvent(
                match ($stepState->status) {
                    FlowStepStatus::COMPLETED => FlowEventType::STEP_COMPLETED,
                    FlowStepStatus::FAILED => FlowEventType::STEP_FAILED,
                    FlowStepStatus::SKIPPED => FlowEventType::STEP_SKIPPED,
                    FlowStepStatus::PENDING => $stepState->metadata['deferred'] ?? false
                        ? FlowEventType::STEP_DEFERRED
                        : FlowEventType::STEP_STARTED,
                    default => FlowEventType::STEP_STARTED,
                },
                $definition->key,
                $step->id,
                ['status' => $stepState->status->value],
            ));
        }

        $result = $this->evaluator->evaluate($definition, $state);
        $this->events->record(new \Webong\WorkFlow\ValueObjects\FlowEvent(
            match ($result->status) {
                \Webong\WorkFlow\Enums\FlowStatus::COMPLETED => FlowEventType::COMPLETED,
                \Webong\WorkFlow\Enums\FlowStatus::ATTENTION => FlowEventType::ATTENTION_REQUIRED,
                \Webong\WorkFlow\Enums\FlowStatus::BLOCKED => FlowEventType::BLOCKED,
                default => FlowEventType::STARTED,
            },
            $definition->key,
        ));

        return $result;
    }

}
