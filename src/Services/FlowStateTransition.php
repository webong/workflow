<?php

declare(strict_types=1);

namespace Webong\WebFlow\Services;

use InvalidArgumentException;
use Webong\WebFlow\Contracts\FlowDeferredCompletionHandler;
use Webong\WebFlow\Enums\FlowStepStatus;
use Webong\WebFlow\ValueObjects\FlowDefinition;
use Webong\WebFlow\ValueObjects\FlowState;
use Webong\WebFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WebFlow\ValueObjects\StepResult;
use Webong\WebFlow\ValueObjects\StepState;

final class FlowStateTransition implements FlowDeferredCompletionHandler
{
    public function __construct(
        private readonly FlowEvaluator $evaluator = new FlowEvaluator(),
    ) {
    }

    public function running(FlowState $state, string $stepId): FlowState
    {
        return $state->withStep($stepId, new StepState(
            status: FlowStepStatus::RUNNING,
            updatedAt: date(DATE_ATOM),
        ));
    }

    /** @param array<string, mixed> $metadata */
    public function completed(FlowState $state, string $stepId, ?string $message = null, array $metadata = []): FlowState
    {
        return $state->withStep($stepId, new StepState(
            status: FlowStepStatus::COMPLETED,
            message: $message,
            updatedAt: date(DATE_ATOM),
            metadata: $metadata,
        ));
    }

    /** @param array<string, mixed> $metadata */
    public function failed(FlowState $state, string $stepId, string $error, bool $retriable = false, array $metadata = []): FlowState
    {
        return $state->withStep($stepId, new StepState(
            status: FlowStepStatus::FAILED,
            error: $error,
            updatedAt: date(DATE_ATOM),
            retriable: $retriable,
            attempts: ($state->steps[$stepId]->attempts ?? 0) + 1,
            metadata: $metadata,
        ));
    }

    public function reset(FlowState $state, ?string $stepId = null): FlowState
    {
        if ($stepId === null) {
            return new FlowState(\Webong\WebFlow\Enums\FlowStatus::PENDING, version: $state->version, metadata: $state->metadata);
        }

        return $state->withStep($stepId, new StepState(updatedAt: date(DATE_ATOM)));
    }

    public function complete(
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

        return $this->applyDeferredCompletion($definition, $state, $completion);
    }

    public function completeDeferred(FlowState $state, FlowDeferredCompletion $completion): FlowState
    {
        return $this->applyDeferredCompletion(null, $state, $completion);
    }

    private function applyDeferredCompletion(
        ?FlowDefinition $definition,
        FlowState $state,
        FlowDeferredCompletion $completion,
    ): FlowState {
        $processedKeys = $state->metadata['deferred_idempotency_keys']
            ?? $state->metadata['completed_idempotency_keys']
            ?? [];

        if (is_array($processedKeys) && in_array($completion->idempotencyKey, $processedKeys, true)) {
            return $state;
        }

        $step = $state->steps[$completion->stepId] ?? null;

        if ($step === null) {
            throw new InvalidArgumentException("Unknown deferred flow step '{$completion->stepId}'.");
        }

        if ($step->status !== FlowStepStatus::PENDING || ($step->metadata['deferred'] ?? false) !== true) {
            throw new InvalidArgumentException("Flow step '{$completion->stepId}' is not awaiting deferred completion.");
        }

        $processedKeys = is_array($processedKeys) ? array_values(array_filter($processedKeys, 'is_string')) : [];
        $next = $this->withStepResult($state, $completion->stepId, $completion->result);

        $next = $this->withMetadata($next, [
            'deferred_idempotency_keys' => [...$processedKeys, $completion->idempotencyKey],
        ]);

        return $definition instanceof FlowDefinition
            ? $this->evaluator->evaluate($definition, $next)
            : $next;
    }

    private function withStepResult(FlowState $state, string $stepId, StepResult $result): FlowState
    {
        $step = $result->toState();
        $previous = $state->steps[$stepId] ?? null;

        if ($previous instanceof StepState && $step->attempts === 0) {
            $metadata = $step->metadata;

            if ($step->status === FlowStepStatus::PENDING && ($previous->metadata['deferred'] ?? false) === true) {
                $metadata = [...$previous->metadata, ...$metadata, 'deferred' => true];
            }

            $step = new StepState(
                status: $step->status,
                message: $step->message,
                error: $step->error,
                updatedAt: $step->updatedAt,
                retriable: $step->retriable,
                attempts: $previous->attempts,
                nextRetryAt: $step->nextRetryAt,
                metadata: $metadata,
            );
        }

        return $state->withStep($stepId, $step);
    }

    /** @param array<string, mixed> $metadata */
    private function withMetadata(FlowState $state, array $metadata): FlowState
    {
        return new FlowState(
            status: $state->status,
            steps: $state->steps,
            currentStep: $state->currentStep,
            failedSteps: $state->failedSteps,
            canRetryStep: $state->canRetryStep,
            message: $state->message,
            metadata: [...$state->metadata, ...$metadata],
            version: $state->version,
        );
    }
}
