<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use Zorvia\WebFlow\Enums\FlowStatus;
use Zorvia\WebFlow\ValueObjects\FlowAction;
use Zorvia\WebFlow\ValueObjects\FlowPresentation;
use Zorvia\WebFlow\ValueObjects\FlowState;

final class FlowPresentationFactory
{
    public function fromState(
        FlowState $state,
        ?string $message = null,
        ?FlowAction $action = null,
    ): FlowPresentation {
        $actions = $action instanceof FlowAction ? [$action] : [];
        $hasFailedStep = $state->failedSteps !== [] || array_filter(
            $state->steps,
            static fn (\Zorvia\WebFlow\ValueObjects\StepState $step): bool => $step->status === \Zorvia\WebFlow\Enums\FlowStepStatus::FAILED,
        ) !== [];
        $status = $hasFailedStep && $state->status === FlowStatus::RUNNING ? FlowStatus::ATTENTION : $state->status;
        $defaults = match ($status) {
            FlowStatus::PENDING => ['info', 'Flow is ready to begin.'],
            FlowStatus::RUNNING => ['info', 'Flow is in progress.'],
            FlowStatus::ATTENTION => ['warning', 'Review this flow and take the required action.'],
            FlowStatus::BLOCKED => ['error', 'This flow is blocked until the issue is resolved.'],
            FlowStatus::COMPLETED => ['success', 'Flow completed successfully.'],
        };

        return new FlowPresentation(
            severity: $defaults[0],
            message: $message ?? $state->message ?? $defaults[1],
            actions: $actions,
        );
    }
}
