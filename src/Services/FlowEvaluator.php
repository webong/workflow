<?php

declare(strict_types=1);

namespace Webong\WebFlow\Services;

use Webong\WebFlow\Enums\FlowStatus;
use Webong\WebFlow\Enums\FlowStepStatus;
use Webong\WebFlow\ValueObjects\FlowDefinition;
use Webong\WebFlow\ValueObjects\FlowState;
use Webong\WebFlow\ValueObjects\StepState;

final class FlowEvaluator
{
    public function evaluate(FlowDefinition $definition, ?FlowState $stored = null): FlowState
    {
        $storedSteps = $stored instanceof FlowState ? $stored->steps : [];
        $steps = [];
        $failedSteps = [];
        $criticalFailed = [];
        $criticalPending = [];
        $canRetryStep = null;

        foreach ($definition->steps as $definitionStep) {
            $step = $storedSteps[$definitionStep->id] ?? new StepState();
            $steps[$definitionStep->id] = $step;

            $dependencyPending = array_filter(
                $definitionStep->dependsOn,
                static fn (string $dependency): bool => ! isset($steps[$dependency])
                    || ! in_array($steps[$dependency]->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED], true),
            );

            if ($dependencyPending !== [] && $step->status === FlowStepStatus::PENDING) {
                $criticalPending[] = $definitionStep->id;
                continue;
            }

            if ($step->status === FlowStepStatus::FAILED) {
                $failedSteps[] = $definitionStep->id;

                if ($definitionStep->critical) {
                    $criticalFailed[] = $definitionStep->id;
                }

                $canRetry = $definitionStep->retryPolicy?->canRetry($step->attempts)
                    ?? ($step->retriable ?? $definitionStep->retriable);

                if ($canRetryStep === null && $canRetry) {
                    $canRetryStep = $definitionStep->id;
                }

                continue;
            }

            if ($definitionStep->critical && ! in_array($step->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED], true)) {
                $criticalPending[] = $definitionStep->id;
            }
        }

        if ($criticalFailed !== []) {
            $status = $canRetryStep !== null ? FlowStatus::ATTENTION : FlowStatus::BLOCKED;
            $message = $steps[$canRetryStep ?? $criticalFailed[0]]->error ?? 'A required flow step failed.';
        } elseif ($criticalPending !== []) {
            $status = FlowStatus::RUNNING;
            $message = 'Flow is waiting for: '.implode(', ', $criticalPending);
        } elseif ($failedSteps !== []) {
            $status = FlowStatus::COMPLETED;
            $message = 'Flow completed with non-critical step failures.';
        } elseif ($definition->steps === []) {
            $status = FlowStatus::COMPLETED;
            $message = null;
        } else {
            $status = FlowStatus::COMPLETED;
            $message = null;
        }

        return new FlowState(
            status: $status,
            steps: $steps,
            currentStep: $stored?->currentStep,
            failedSteps: $failedSteps,
            canRetryStep: $canRetryStep,
            message: $message,
            metadata: $stored instanceof FlowState ? $stored->metadata : [],
        );
    }
}
