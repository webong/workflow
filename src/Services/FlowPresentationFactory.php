<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\ValueObjects\FlowAction;
use Webong\WorkFlow\ValueObjects\FlowPresentation;
use Webong\WorkFlow\ValueObjects\FlowState;

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
            static fn (\Webong\WorkFlow\ValueObjects\StepState $step): bool => $step->status === \Webong\WorkFlow\Enums\FlowStepStatus::FAILED,
        ) !== [];
        $status = $hasFailedStep && $state->status === FlowStatus::RUNNING ? FlowStatus::ATTENTION : $state->status;
        $defaults = match ($status) {
            FlowStatus::PENDING => ['info', 'Flow is ready to begin.'],
            FlowStatus::RUNNING => ['info', 'Flow is in progress.'],
            FlowStatus::ATTENTION => ['warning', 'Review this flow and take the required action.'],
            FlowStatus::BLOCKED => ['error', 'This flow is blocked until the issue is resolved.'],
            FlowStatus::COMPLETED => ['success', 'Flow completed successfully.'],
            FlowStatus::CANCELLED => ['info', 'Flow cancelled.'],
        };

        return new FlowPresentation(
            severity: $defaults[0],
            message: $message ?? $state->message ?? $defaults[1],
            actions: $actions,
        );
    }
}
