<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Temporal;

use InvalidArgumentException;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\FlowEvaluator;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepState;

/** Temporal history owns this state. External stores may only hold projections. */
#[\Temporal\Workflow\WorkflowInterface]
final class TemporalFlowWorkflow
{
    private TemporalFlowActivityInterface $activities;
    private ?FlowState $state = null;
    private ?FlowDefinition $definition = null;
    private TemporalCompletionInbox $completions;
    private int $rejectedCompletions = 0;

    public function __construct()
    {
        $this->completions = new TemporalCompletionInbox();
        $this->activities = \Temporal\Workflow::newActivityStub(
            TemporalFlowActivityInterface::class,
            \Temporal\Activity\ActivityOptions::new()
                ->withStartToCloseTimeout('5 minutes')
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
        $definition = $this->definition = FlowDefinition::fromArray($definitionData);
        $this->state = FlowState::fromArray($stateData);
        $this->state->run?->assertDefinition($definition);

        foreach ($definition->executionSteps() as $step) {
            if ($this->state->status === FlowStatus::CANCELLED || ! $this->dependenciesCompleted($step, $this->state)) {
                continue;
            }

            while (true) {
                $previous = $this->state->steps[$step->id] ?? new StepState();
                if (in_array($previous->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED, FlowStepStatus::RUNNING], true)) {
                    break;
                }

                if ($this->isDeferred($previous)) {
                    yield \Temporal\Workflow::await(fn (): bool => $this->completions->has($step->id));
                    $completion = $this->completions->shift($step->id);
                    if ($completion !== null) {
                        try {
                            $this->state = (new FlowStateTransition(
                                timestamp: static fn (): string => \Temporal\Workflow::now()->format(DATE_ATOM),
                                clock: static fn (): int => \Temporal\Workflow::now()->getTimestamp(),
                            ))
                                ->complete($definition, $this->state, $completion);
                        } catch (InvalidArgumentException) {
                            // A stale or malformed signal must not poison workflow replay.
                            $this->rejectedCompletions++;
                        }
                    }
                    continue;
                }

                if ($previous->status === FlowStepStatus::FAILED) {
                    if (! $this->shouldRetry($step, $previous)) {
                        break;
                    }
                    $delay = $previous->nextRetryAt !== null
                        ? max(0, $previous->nextRetryAt - \Temporal\Workflow::now()->getTimestamp())
                        : ($step->retryPolicy->backoffSeconds ?? 0);
                    if ($delay > 0) {
                        yield \Temporal\Workflow::timer($delay);
                    }
                }

                $this->state = $this->state->withStep($step->id, new StepState(
                    FlowStepStatus::RUNNING,
                    updatedAt: $previous->updatedAt,
                    attempts: $previous->attempts + 1,
                ));
                /** @var array<string, mixed> $stepData */
                $stepData = yield $this->activities->execute([
                    'definition' => $definition->toArray(),
                    'step_id' => $step->id,
                    'run_id' => $this->state->run?->id,
                    'context' => $context,
                    'previous' => $previous->toArray(),
                ]);
                $result = StepState::fromArray($stepData);
                $this->state = (new FlowEvaluator())->evaluate($definition, $this->state->withStep($step->id, $result));
                if ($result->status === FlowStepStatus::PENDING && ! $this->isDeferred($result)) {
                    break;
                }
            }
        }

        $this->state = (new FlowEvaluator())->evaluate($definition, $this->state);

        return $this->state->toArray();
    }

    /** @param array<string, mixed> $completion */
    #[\Temporal\Workflow\SignalMethod]
    public function complete(array $completion): void
    {
        try {
            $parsed = TemporalFlowCompletion::fromArray($completion)->completion;
            if ($this->state !== null && ($parsed->runId !== $this->state->run?->id
                || $parsed->flowKey !== $this->definition?->key
                || $this->definition?->step($parsed->stepId) === null)) {
                throw new InvalidArgumentException('Signal does not belong to this run.');
            }
            $step = $this->state?->steps[$parsed->stepId] ?? null;
            if ($step === null || ($this->state?->run !== null && $parsed->attempt !== $step->attempts)) {
                throw new InvalidArgumentException('Signal targets an unknown or stale step attempt.');
            }
            if (in_array($step->status, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED, FlowStepStatus::FAILED], true)) {
                // Acknowledged duplicates do not consume inbox capacity.
                (new FlowStateTransition())->complete($this->definition, $this->state, $parsed);
                return;
            }
            if ($step->status !== FlowStepStatus::RUNNING && ! $this->isDeferred($step)) {
                throw new InvalidArgumentException('Signal targets a step that has not started.');
            }
            $this->completions->push($parsed);
        } catch (InvalidArgumentException) {
            $this->rejectedCompletions++;
        }
    }

    /** @return array{state: array<string, mixed>|null, rejected_completions: int} */
    #[\Temporal\Workflow\QueryMethod]
    public function snapshot(): array
    {
        return ['state' => $this->state?->toArray(), 'rejected_completions' => $this->rejectedCompletions];
    }

    private function dependenciesCompleted(FlowStepDefinition $step, FlowState $state): bool
    {
        foreach ($step->dependsOn as $dependency) {
            if (! in_array($state->steps[$dependency]->status ?? null, [FlowStepStatus::COMPLETED, FlowStepStatus::SKIPPED], true)) {
                return false;
            }
        }

        return true;
    }

    private function shouldRetry(FlowStepDefinition $step, StepState $state): bool
    {
        return $state->status === FlowStepStatus::FAILED
            && $state->retriable !== false
            && $step->retryPolicy?->canRetry($state->attempts) === true;
    }

    private function isDeferred(StepState $state): bool
    {
        return $state->status === FlowStepStatus::PENDING && ($state->metadata['deferred'] ?? false) === true;
    }
}
