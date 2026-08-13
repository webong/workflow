<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use InvalidArgumentException;
use Zorvia\WebFlow\Enums\FlowStepStatus;
use Zorvia\WebFlow\ValueObjects\FlowState;
use Zorvia\WebFlow\ValueObjects\FlowDeferredCompletion;
use Zorvia\WebFlow\ValueObjects\StepState;

final class FlowStateTransition
{
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
            return new FlowState(\Zorvia\WebFlow\Enums\FlowStatus::PENDING, version: $state->version, metadata: $state->metadata);
        }

        return $state->withStep($stepId, new StepState(updatedAt: date(DATE_ATOM)));
    }

    public function completeDeferred(FlowState $state, FlowDeferredCompletion $completion): FlowState
    {
        $step = $state->steps[$completion->stepId] ?? null;

        if ($step === null) {
            throw new InvalidArgumentException("Unknown deferred flow step '{$completion->stepId}'.");
        }

        $completedKeys = $state->metadata['completed_idempotency_keys'] ?? [];

        if (is_array($completedKeys) && in_array($completion->idempotencyKey, $completedKeys, true)) {
            return $state;
        }

        $completedKeys = is_array($completedKeys) ? array_values(array_filter($completedKeys, 'is_string')) : [];

        return $this->withMetadata(
            $this->completed($state, $completion->stepId, $completion->result->message, $completion->result->metadata),
            ['completed_idempotency_keys' => [...$completedKeys, $completion->idempotencyKey]],
        );
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
