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
        if (! in_array($state->status, [FlowStatus::ATTENTION, FlowStatus::BLOCKED], true)) {
            return null;
        }

        $actions = $action instanceof FlowAction ? [$action] : [];

        return new FlowPresentation(
            kind: PresentationKind::BANNER,
            severity: $state->status === FlowStatus::BLOCKED ? 'error' : 'warning',
            title: $title,
            message: $message ?? $state->message ?? 'Review this flow and take the required action.',
            actions: $actions,
        );
    }
}
