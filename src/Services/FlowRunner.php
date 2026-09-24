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
use Webong\WorkFlow\ValueObjects\StepState;
use Webong\WorkFlow\Services\NullFlowEventSink;

final class FlowRunner
{
    public function __construct(
        private readonly FlowEvaluator $evaluator = new FlowEvaluator(),
        private readonly FlowEventSink $events = new NullFlowEventSink(),
        private readonly FlowStepExecution $execution = new FlowStepExecution(),
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
        $executors = is_array($executors) ? $executors : iterator_to_array($executors, false);
        $this->events->record(new \Webong\WorkFlow\ValueObjects\FlowEvent(FlowEventType::STARTED, $definition->key));

        foreach ($definition->executionSteps() as $step) {
            $previous = $state->steps[$step->id] ?? new StepState();

            if (in_array($previous->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED, FlowStepStatus::RUNNING], true)
                || ($previous->status === FlowStepStatus::PENDING && ($previous->metadata['deferred'] ?? false) === true)) {
                continue;
            }

            if ($previous->status === FlowStepStatus::FAILED
                && (! ($previous->retriable ?? $step->retryPolicy->enabled ?? $step->retriable)
                    || ! ($step->retryPolicy?->canRetry($previous->attempts) ?? true))) {
                continue;
            }

            if ($previous->nextRetryAt !== null && $previous->nextRetryAt > time()) {
                continue;
            }

            if ($step->dependsOn !== [] && ! $this->dependenciesCompleted($step->dependsOn, $state)) {
                continue;
            }

            $executor = null;
            foreach ($executors as $candidate) {
                if ($candidate instanceof FlowStepExecutor && $candidate->supports($step)) {
                    $executor = $candidate;
                    break;
                }
            }

            if (! $executor instanceof FlowStepExecutor) {
                throw new RuntimeException("No executor registered for flow step '{$step->id}'.");
            }

            $this->events->record(new \Webong\WorkFlow\ValueObjects\FlowEvent(FlowEventType::STEP_STARTED, $definition->key, $step->id));
            $stepState = $this->execution->execute($executor, $step, $context, $previous, $definition->key, $state->run?->id);
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

    /** @param list<string> $dependencies */
    private function dependenciesCompleted(array $dependencies, FlowState $state): bool
    {
        foreach ($dependencies as $dependency) {
            $step = $state->steps[$dependency] ?? null;

            if ($step === null || ! in_array($step->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED], true)) {
                return false;
            }
        }

        return true;
    }

}
