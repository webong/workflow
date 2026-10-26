<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Pure eligibility policy for execution drivers.
 *
 * The returned steps are simultaneously ready for the supplied state
 * snapshot. A host may execute them sequentially or claim them atomically and
 * fan them out; core deliberately owns neither threads nor queue semantics.
 */
final class FlowReadySet
{
    /** @return list<FlowStepDefinition> */
    public function steps(FlowDefinition $definition, FlowState $state, ?int $now = null): array
    {
        $state->run?->assertDefinition($definition);

        if (in_array($state->status, [FlowStatus::CANCELLED, FlowStatus::COMPLETED], true)) {
            return [];
        }

        $now ??= time();
        $ready = [];
        foreach ($definition->executionSteps() as $step) {
            if ($this->isReady($step, $state, $now)) {
                $ready[] = $step;
            }
        }

        return $ready;
    }

    public function isReady(FlowStepDefinition $step, FlowState $state, ?int $now = null): bool
    {
        if (in_array($state->status, [FlowStatus::CANCELLED, FlowStatus::COMPLETED], true)) {
            return false;
        }

        $previous = $state->steps[$step->id] ?? new StepState();
        if (in_array($previous->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED, FlowStepStatus::RUNNING], true)) {
            return false;
        }

        if ($previous->status === FlowStepStatus::PENDING
            && ($previous->metadata['deferred'] ?? false) === true) {
            return false;
        }

        if ($previous->status === FlowStepStatus::FAILED
            && (! ($previous->retriable ?? $step->retryPolicy->enabled ?? $step->retriable)
                || ! ($step->retryPolicy?->canRetry($previous->attempts) ?? true))) {
            return false;
        }

        if ($previous->nextRetryAt !== null && $previous->nextRetryAt > ($now ?? time())) {
            return false;
        }

        foreach ($step->dependsOn as $dependency) {
            $dependencyState = $state->steps[$dependency] ?? null;
            if ($dependencyState === null
                || ! in_array($dependencyState->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED], true)) {
                return false;
            }
        }

        return true;
    }
}
