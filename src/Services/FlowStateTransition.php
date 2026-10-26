<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;
use Closure;
use Webong\WorkFlow\Contracts\FlowDeferredCompletionHandler;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

final class FlowStateTransition implements FlowDeferredCompletionHandler
{
    public const int MAX_DEFERRED_EVENTS = 1024;

    /**
     * @param null|Closure(): ?string $timestamp Deterministic runtimes supply their recorded time.
     * @param null|Closure(): int $clock Epoch seconds used for retry deadlines.
     */
    public function __construct(
        private readonly FlowEvaluator $evaluator = new FlowEvaluator(),
        private readonly ?Closure $timestamp = null,
        private readonly ?Closure $clock = null,
    ) {
    }

    public function running(FlowState $state, string $stepId): FlowState
    {
        return $state->withStep($stepId, new StepState(
            status: FlowStepStatus::RUNNING,
            updatedAt: date(\DATE_ATOM),
        ));
    }

    /** @param array<string, mixed> $metadata */
    public function completed(FlowState $state, string $stepId, ?string $message = null, array $metadata = [], ?StepOutput $output = null): FlowState
    {
        return $state->withStep($stepId, new StepState(
            status: FlowStepStatus::COMPLETED,
            message: $message,
            updatedAt: date(\DATE_ATOM),
            metadata: $metadata,
            output: $output,
        ));
    }

    /** @param array<string, mixed> $metadata */
    public function failed(FlowState $state, string $stepId, string $error, bool $retriable = false, array $metadata = []): FlowState
    {
        return $state->withStep($stepId, new StepState(
            status: FlowStepStatus::FAILED,
            error: $error,
            updatedAt: date(\DATE_ATOM),
            retriable: $retriable,
            attempts: ($state->steps[$stepId]->attempts ?? 0) + 1,
            metadata: $metadata,
        ));
    }

    public function reset(FlowState $state, ?string $stepId = null): FlowState
    {
        if ($state->run !== null) {
            throw new InvalidArgumentException('Start a new run to replay work; resetting a run would invalidate callback identity.');
        }
        if ($stepId === null) {
            return new FlowState(\Webong\WorkFlow\Enums\FlowStatus::PENDING, version: $state->version, metadata: $state->metadata);
        }

        return $state->withStep($stepId, new StepState(updatedAt: date(\DATE_ATOM)));
    }

    public function cancel(FlowState $state): FlowState
    {
        if ($state->status === \Webong\WorkFlow\Enums\FlowStatus::COMPLETED) {
            throw new InvalidArgumentException('A completed flow cannot be cancelled.');
        }

        return new FlowState(
            status: \Webong\WorkFlow\Enums\FlowStatus::CANCELLED,
            steps: $state->steps,
            currentStep: $state->currentStep,
            failedSteps: $state->failedSteps,
            message: 'Flow cancelled.',
            metadata: $state->metadata,
            version: $state->version,
            run: $state->run,
        );
    }

    /**
     * Explicit timeout transition for a waiting deferred step whose wait
     * deadline passed. The host (or its driver) owns the wakeup that calls
     * this; core never expires work on its own clock. The step becomes a
     * terminal non-retriable failure so the run can surface for operator
     * reconciliation, exactly like an explicit business failure.
     */
    public function timedOut(FlowDefinition $definition, FlowState $state, string $stepId, ?string $message = null): FlowState
    {
        $state->run?->assertDefinition($definition);

        if ($definition->step($stepId) === null) {
            throw new InvalidArgumentException("Unknown flow definition step '{$stepId}'.");
        }

        $step = $state->steps[$stepId] ?? null;
        if ($step === null
            || $step->status !== FlowStepStatus::PENDING
            || ($step->metadata['deferred'] ?? false) !== true) {
            throw new InvalidArgumentException("Flow step '{$stepId}' is not awaiting deferred completion.");
        }

        if ($state->status === \Webong\WorkFlow\Enums\FlowStatus::CANCELLED) {
            throw new InvalidArgumentException('A cancelled run cannot time out.');
        }

        return $this->evaluator->evaluate($definition, $state->withStep($stepId, new StepState(
            status: FlowStepStatus::FAILED,
            error: $message ?? 'Wait deadline exceeded.',
            updatedAt: $this->timestamp !== null ? ($this->timestamp)() : date(\DATE_ATOM),
            retriable: false,
            attempts: $step->attempts,
        )));
    }

    /**
     * Applies the outcome recorded for a deferred deadline. Ordinary
     * deadlines fail through timedOut(); scheduled waits opt into successful
     * completion with `deadline_transition: complete`.
     */
    public function deadlineReached(FlowDefinition $definition, FlowState $state, string $stepId): FlowState
    {
        $state->run?->assertDefinition($definition);

        if ($definition->step($stepId) === null) {
            throw new InvalidArgumentException("Unknown flow definition step '{$stepId}'.");
        }

        if ($state->status === \Webong\WorkFlow\Enums\FlowStatus::CANCELLED) {
            throw new InvalidArgumentException('A cancelled run cannot reach a deadline.');
        }

        $step = $state->steps[$stepId] ?? null;
        if ($step === null
            || $step->status !== FlowStepStatus::PENDING
            || ($step->metadata['deferred'] ?? false) !== true
            || $step->deadlineAt === null) {
            throw new InvalidArgumentException("Flow step '{$stepId}' has no deferred deadline.");
        }
        $now = $this->clock !== null ? ($this->clock)() : time();
        if ($step->deadlineAt > $now) {
            throw new InvalidArgumentException("Flow step '{$stepId}' deadline has not been reached.");
        }
        if (($step->metadata['deadline_transition'] ?? null) !== 'complete') {
            return $this->timedOut($definition, $state, $stepId);
        }

        $metadata = $step->metadata;
        unset($metadata['deferred'], $metadata['deadline_transition'], $metadata['deadline_output_present'], $metadata['deadline_output']);
        $output = ($step->metadata['deadline_output_present'] ?? false) === true
            ? StepOutput::from($step->metadata['deadline_output'] ?? null)
            : null;
        $next = $this->withStepResult(
            $state,
            $stepId,
            StepResult::completed('Scheduled wait completed.', $metadata, $output),
            $definition,
        );

        return $this->evaluator->evaluate($definition, $next);
    }

    public function complete(
        FlowDefinition $definition,
        FlowState $state,
        FlowDeferredCompletion $completion,
    ): FlowState {
        $state->run?->assertDefinition($definition);
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
        if ($state->run !== null) {
            return $this->complete($state->run->definition, $state, $completion);
        }

        return $this->applyDeferredCompletion(null, $state, $completion);
    }

    private function applyDeferredCompletion(
        ?FlowDefinition $definition,
        FlowState $state,
        FlowDeferredCompletion $completion,
    ): FlowState {
        if ($state->run?->id !== $completion->runId
            || ($state->run !== null && $state->run->definition->key !== $completion->flowKey)) {
            throw new InvalidArgumentException('Deferred completion belongs to a different flow run.');
        }

        if ($state->status === \Webong\WorkFlow\Enums\FlowStatus::CANCELLED) {
            throw new InvalidArgumentException('A cancelled run cannot accept completions.');
        }

        $step = $state->steps[$completion->stepId] ?? null;

        if ($step === null) {
            throw new InvalidArgumentException("Unknown deferred flow step '{$completion->stepId}'.");
        }

        if ($state->run !== null && $completion->attempt !== $step->attempts) {
            throw new InvalidArgumentException('Deferred completion belongs to a different step attempt.');
        }

        $events = $state->metadata['deferred_events'] ?? [];
        $events = is_array($events) ? $events : [];
        $eventKey = hash('sha256', json_encode([
            $completion->runId, $completion->stepId, $completion->attempt, $completion->idempotencyKey,
        ], JSON_THROW_ON_ERROR));
        $fingerprint = CanonicalFlowPayload::fingerprint([
            $completion->result->status->value, $completion->result->message, $completion->result->error,
            $completion->result->retriable, $completion->result->metadata,
            $completion->result->nextRetryAt, $completion->result->deferred,
            // The presence flag keeps an absent output distinct from a
            // present JSON null when duplicate events are compared.
            $completion->result->output !== null,
            $completion->result->output?->value,
            $completion->result->deadlineAt,
        ]);

        if (isset($events[$eventKey])) {
            if ($events[$eventKey] !== $fingerprint) {
                throw new InvalidArgumentException('A completion event id cannot be reused with a different result.');
            }

            return $state;
        }

        $legacyKeys = $state->metadata['deferred_idempotency_keys'] ?? $state->metadata['completed_idempotency_keys'] ?? [];
        if ($state->run === null && is_array($legacyKeys) && in_array($completion->idempotencyKey, $legacyKeys, true)) {
            return $state;
        }

        if ($step->status !== FlowStepStatus::PENDING || ($step->metadata['deferred'] ?? false) !== true) {
            throw new InvalidArgumentException("Flow step '{$completion->stepId}' is not awaiting deferred completion.");
        }

        if (count($events) >= self::MAX_DEFERRED_EVENTS) {
            throw new InvalidArgumentException('The run has reached its deferred completion event limit.');
        }
        $next = $this->withStepResult($state, $completion->stepId, $completion->result, $definition ?? $state->run?->definition);

        $next = $next->withMetadata([
            'deferred_events' => [...$events, $eventKey => $fingerprint],
        ]);

        return $definition instanceof FlowDefinition
            ? $this->evaluator->evaluate($definition, $next)
            : $next;
    }

    private function withStepResult(FlowState $state, string $stepId, StepResult $result, ?FlowDefinition $definition): FlowState
    {
        $previous = $state->steps[$stepId] ?? null;
        $attempts = $previous->attempts ?? 0;
        $metadata = $result->metadata;
        if ($result->status === FlowStepStatus::PENDING) {
            $metadata = [...($previous->metadata ?? []), ...$metadata, 'deferred' => true];
        } else {
            unset($metadata['deferred']);
        }

        $retriable = $result->retriable;
        $nextRetryAt = $result->nextRetryAt;
        $deadlineAt = $result->status === FlowStepStatus::PENDING ? $result->deadlineAt : null;
        $step = $definition?->step($stepId);
        if ($result->status === FlowStepStatus::FAILED && $step !== null) {
            $retriable = ($retriable ?? $step->retryPolicy->enabled ?? $step->retriable)
                && ($step->retryPolicy?->canRetry($attempts) ?? true);
            $now = $this->clock !== null ? ($this->clock)() : time();
            $nextRetryAt = $retriable
                ? max($nextRetryAt ?? 0, $now + ($step->retryPolicy->backoffSeconds ?? 0))
                : null;
        }

        return $state->withStep($stepId, new StepState(
            status: $result->status,
            message: $result->message,
            error: $result->error,
            updatedAt: $this->timestamp !== null ? ($this->timestamp)() : date(\DATE_ATOM),
            retriable: $retriable,
            attempts: $attempts,
            nextRetryAt: $nextRetryAt,
            metadata: $metadata,
            output: $result->output,
            deadlineAt: $deadlineAt,
        ));
    }
}
