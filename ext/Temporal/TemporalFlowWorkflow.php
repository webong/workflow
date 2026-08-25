<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Temporal;

use InvalidArgumentException;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\FlowEvaluator;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * A deterministic Temporal Workflow that delegates side effects to Activities.
 *
 * temporal/sdk is optional and must be installed by the host application.
 */
#[\Temporal\Workflow\WorkflowInterface]
final class TemporalFlowWorkflow
{
    private TemporalFlowActivityInterface $activities;

    /** @var array<string, array<string, mixed>> */
    private array $completions = [];

    public function __construct()
    {
        $this->activities = \Temporal\Workflow::newActivityStub(
            TemporalFlowActivityInterface::class,
            \Temporal\Activity\ActivityOptions::new()
                ->withStartToCloseTimeout('5 minutes')
                // WorkFlow owns retry policy and applies it deterministically below.
                ->withRetryOptions(\Temporal\Common\RetryOptions::new()->withMaximumAttempts(1)),
        );
    }

    /**
     * @param array<string, mixed> $definitionData
     * @param array<string, mixed> $stateData
     * @param array<string, mixed> $context
     * @return \Generator<int, mixed, mixed, array<string, mixed>>
     */
    #[\Temporal\Workflow\WorkflowMethod]
    public function run(array $definitionData, array $stateData, array $context = []): \Generator
    {
        $definition = FlowDefinition::fromArray($definitionData);
        $state = FlowState::fromArray($stateData);

        foreach ($definition->steps as $step) {
            $previous = $state->steps[$step->id] ?? new StepState();

            if (in_array($previous->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED], true)) {
                continue;
            }

            if (! $this->dependenciesCompleted($step, $state)) {
                continue;
            }

            do {
                /** @var array<string, mixed> $stepData */
                $stepData = yield $this->activities->execute([
                    'definition' => $definition->toArray(),
                    'step_id' => $step->id,
                    'context' => $context,
                    'previous' => $previous->toArray(),
                ]);

                $previous = StepState::fromArray($stepData);
                $state = $state->withStep($step->id, $previous);

                if (! $this->shouldRetry($step, $previous)) {
                    break;
                }

                if ($step->retryPolicy?->backoffSeconds > 0) {
                    yield \Temporal\Workflow::timer($step->retryPolicy->backoffSeconds);
                }
            } while (true);

            while ($this->isDeferred($previous)) {
                yield \Temporal\Workflow::await(
                    fn (): bool => array_key_exists($step->id, $this->completions),
                );

                $completionData = $this->completions[$step->id];
                unset($this->completions[$step->id]);

                $completion = TemporalFlowCompletion::fromArray($completionData)->completion;
                $state = $this->applyCompletion($definition, $state, $completion);
                $previous = $state->steps[$step->id] ?? new StepState();
            }
        }

        return (new FlowEvaluator())->evaluate($definition, $state)->toArray();
    }

    /**
     * Delivers a provider callback to the deferred step waiting in this run.
     *
     * @param array<string, mixed> $completion
     */
    #[\Temporal\Workflow\SignalMethod]
    public function complete(array $completion): void
    {
        $stepId = $completion['step_id'] ?? null;

        if (is_string($stepId) && $stepId !== '') {
            $this->completions[$stepId] = $completion;
        }
    }

    private function dependenciesCompleted(FlowStepDefinition $step, FlowState $state): bool
    {
        foreach ($step->dependsOn as $dependency) {
            $dependencyState = $state->steps[$dependency] ?? null;

            if ($dependencyState === null
                || ! in_array($dependencyState->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED], true)) {
                return false;
            }
        }

        return true;
    }

    private function shouldRetry(FlowStepDefinition $step, StepState $state): bool
    {
        return $state->status === FlowStepStatus::FAILED
            && $step->retryPolicy?->canRetry($state->attempts) === true;
    }

    private function isDeferred(StepState $state): bool
    {
        return $state->status === FlowStepStatus::PENDING
            && ($state->metadata['deferred'] ?? false) === true;
    }

    private function applyCompletion(
        FlowDefinition $definition,
        FlowState $state,
        FlowDeferredCompletion $completion,
    ): FlowState {
        if ($completion->flowKey !== $definition->key) {
            throw new InvalidArgumentException("Deferred completion belongs to flow '{$completion->flowKey}', not '{$definition->key}'.");
        }

        if ($definition->step($completion->stepId) === null) {
            throw new InvalidArgumentException("Unknown flow definition step '{$completion->stepId}'.");
        }

        $processedKeys = $state->metadata['deferred_idempotency_keys']
            ?? $state->metadata['completed_idempotency_keys']
            ?? [];

        if (is_array($processedKeys) && in_array($completion->idempotencyKey, $processedKeys, true)) {
            return $state;
        }

        $previous = $state->steps[$completion->stepId] ?? null;

        if ($previous === null) {
            throw new InvalidArgumentException("Unknown deferred flow step '{$completion->stepId}'.");
        }

        if ($previous->status !== FlowStepStatus::PENDING || ($previous->metadata['deferred'] ?? false) !== true) {
            throw new InvalidArgumentException("Flow step '{$completion->stepId}' is not awaiting deferred completion.");
        }

        $result = $completion->result;
        $metadata = $result->metadata;

        if ($result->status === FlowStepStatus::PENDING && ($previous->metadata['deferred'] ?? false) === true) {
            $metadata = [...$previous->metadata, ...$metadata, 'deferred' => true];
        }

        $nextStep = new StepState(
            status: $result->status,
            message: $result->message,
            error: $result->error,
            // Preserve the last recorded timestamp; calling date() would make
            // workflow replay non-deterministic.
            updatedAt: $previous->updatedAt,
            retriable: $result->retriable,
            attempts: $result->attempts === 0 ? $previous->attempts : $result->attempts,
            nextRetryAt: $result->nextRetryAt,
            metadata: $metadata,
        );

        $processedKeys = is_array($processedKeys)
            ? array_values(array_filter($processedKeys, 'is_string'))
            : [];

        $next = $state->withStep($completion->stepId, $nextStep);
        $next = new FlowState(
            status: $next->status,
            steps: $next->steps,
            currentStep: $next->currentStep,
            failedSteps: $next->failedSteps,
            canRetryStep: $next->canRetryStep,
            message: $next->message,
            metadata: [...$next->metadata, 'deferred_idempotency_keys' => [...$processedKeys, $completion->idempotencyKey]],
            version: $next->version,
        );

        return (new FlowEvaluator())->evaluate($definition, $next);
    }
}
