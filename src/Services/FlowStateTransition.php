<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use Zorvia\WebFlow\Enums\StepStatus;
use Zorvia\WebFlow\ValueObjects\FlowState;
use Zorvia\WebFlow\ValueObjects\StepState;

final class FlowStateTransition
{
    public function running(FlowState $state, string $stepId): FlowState
    {
        return $state->withStep($stepId, new StepState(
            status: StepStatus::RUNNING,
            updatedAt: date(DATE_ATOM),
        ));
    }

    public function completed(FlowState $state, string $stepId, ?string $message = null, array $metadata = []): FlowState
    {
        return $state->withStep($stepId, new StepState(
            status: StepStatus::COMPLETED,
            message: $message,
            updatedAt: date(DATE_ATOM),
            metadata: $metadata,
        ));
    }

    public function failed(FlowState $state, string $stepId, string $error, bool $retriable = false, array $metadata = []): FlowState
    {
        return $state->withStep($stepId, new StepState(
            status: StepStatus::FAILED,
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
}
