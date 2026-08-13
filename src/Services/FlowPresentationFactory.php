<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use Zorvia\WebFlow\Enums\FlowStatus;
use Zorvia\WebFlow\Enums\PresentationKind;
use Zorvia\WebFlow\ValueObjects\FlowAction;
use Zorvia\WebFlow\ValueObjects\FlowPresentation;
use Zorvia\WebFlow\ValueObjects\FlowState;

final class FlowPresentationFactory
{
    public function fromState(
        FlowState $state,
        string $title = 'Flow needs attention',
        ?string $message = null,
        ?FlowAction $action = null,
    ): ?FlowPresentation {
        $actions = $action instanceof FlowAction ? [$action] : [];
        $defaults = match ($state->status) {
            FlowStatus::PENDING => ['info', 'Flow is ready to begin.'],
            FlowStatus::RUNNING => ['info', 'Flow is in progress.'],
            FlowStatus::ATTENTION => ['warning', 'Review this flow and take the required action.'],
            FlowStatus::BLOCKED => ['error', 'This flow is blocked until the issue is resolved.'],
            FlowStatus::COMPLETED => ['success', 'Flow completed successfully.'],
        };

        return new FlowPresentation(
            kind: in_array($state->status, [FlowStatus::ATTENTION, FlowStatus::BLOCKED], true)
                ? PresentationKind::BANNER
                : PresentationKind::INLINE,
            severity: $defaults[0],
            title: $title,
            message: $message ?? $state->message ?? $defaults[1],
            actions: $actions,
            dismissible: $state->status === FlowStatus::COMPLETED,
        );
    }
}
