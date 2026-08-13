<?php

declare(strict_types=1);

namespace Webong\WebFlow\Services;

use RuntimeException;
use Throwable;
use Webong\WebFlow\Contracts\FlowContext;
use Webong\WebFlow\Contracts\FlowEventSink;
use Webong\WebFlow\Contracts\FlowStepExecutor;
use Webong\WebFlow\Enums\FlowEventType;
use Webong\WebFlow\Enums\FlowStepStatus;
use Webong\WebFlow\ValueObjects\FlowDefinition;
use Webong\WebFlow\ValueObjects\FlowState;
use Webong\WebFlow\ValueObjects\StepState;
use Webong\WebFlow\Services\NullFlowEventSink;

final class FlowRunner
{
    public function __construct(
        private readonly FlowEvaluator $evaluator = new FlowEvaluator(),
        private readonly FlowEventSink $events = new NullFlowEventSink(),
    )
    {
    }

    /**
     * Executes each supported step once in definition order. The host can
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
        $executors = is_array($executors) ? $executors : iterator_to_array($executors, false);
        $this->events->record(new \Webong\WebFlow\ValueObjects\FlowEvent(FlowEventType::STARTED, $definition->key));

        foreach ($definition->steps as $step) {
            $previous = $state->steps[$step->id] ?? new StepState();

            if (in_array($previous->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED], true)) {
                continue;
            }

            if ($step->retryPolicy instanceof \Webong\WebFlow\ValueObjects\FlowRetryPolicy
                && ! $step->retryPolicy->canRetry($previous->attempts)
                && $previous->status === FlowStepStatus::FAILED) {
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

            $this->events->record(new \Webong\WebFlow\ValueObjects\FlowEvent(FlowEventType::STEP_STARTED, $definition->key, $step->id));
            $stepState = $this->execute($executor, $step, $context, $previous);
            $state = $state->withStep($step->id, new StepState(
                status: $stepState->status,
                message: $stepState->message,
                error: $stepState->error,
                updatedAt: $stepState->updatedAt,
                retriable: $stepState->retriable,
                attempts: $previous->attempts + 1,
                nextRetryAt: $stepState->nextRetryAt,
                metadata: $stepState->metadata,
            ));
            $this->events->record(new \Webong\WebFlow\ValueObjects\FlowEvent(
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
        $this->events->record(new \Webong\WebFlow\ValueObjects\FlowEvent(
            match ($result->status) {
                \Webong\WebFlow\Enums\FlowStatus::COMPLETED => FlowEventType::COMPLETED,
                \Webong\WebFlow\Enums\FlowStatus::ATTENTION => FlowEventType::ATTENTION_REQUIRED,
                \Webong\WebFlow\Enums\FlowStatus::BLOCKED => FlowEventType::BLOCKED,
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

    private function execute(
        FlowStepExecutor $executor,
        \Webong\WebFlow\ValueObjects\StepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepState {
        try {
            $result = $executor->execute($step, $context, $previous);
            $attempts = $previous->attempts + 1;
            $policy = $step->retryPolicy;

            if ($result->status === FlowStepStatus::FAILED && $policy instanceof \Webong\WebFlow\ValueObjects\FlowRetryPolicy) {
                $result = new \Webong\WebFlow\ValueObjects\StepResult(
                    status: $result->status,
                    message: $result->message,
                    error: $result->error,
                    retriable: $policy->canRetry($attempts),
                    attempts: $attempts,
                    nextRetryAt: $policy->canRetry($attempts) ? time() + $policy->backoffSeconds : null,
                    metadata: $result->metadata,
                    deferred: $result->deferred,
                );
            }

            return $result->toState();
        } catch (Throwable $exception) {
            return (new \Webong\WebFlow\ValueObjects\StepResult(
                status: FlowStepStatus::FAILED,
                error: $exception->getMessage() !== '' ? $exception->getMessage() : 'Flow step failed.',
                retriable: $step->retriable,
            ))->toState();
        }
    }
}
