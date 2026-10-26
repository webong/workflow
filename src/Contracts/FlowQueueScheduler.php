<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;

/**
 * Framework-neutral queue scheduler interface.
 *
 * A host implements this to claim ready steps atomically, execute them,
 * and handle retries, cancellation, and crash recovery. The package
 * provides a reference implementation using a database-backed outbox.
 */
interface FlowQueueScheduler
{
    /**
     * Claim the next batch of ready steps for execution.
     *
     * Returns a list of claims. Each claim represents one step that the
     * worker should execute. The claim includes a lease duration after
     * which the step becomes available for another worker.
     *
     * @param int $maxClaims Maximum number of steps to claim in one batch.
     * @param int $leaseSeconds Lease duration for each claim.
     * @return list<FlowStepClaim> Steps claimed for execution.
     */
    public function claim(int $maxClaims, int $leaseSeconds): array;

    /**
     * Complete a claimed step successfully.
     *
     * @param FlowStepClaim $claim The claim returned by claim().
     * @param \Webong\WorkFlow\ValueObjects\StepResult $result The step result.
     * @return void
     */
    public function complete(FlowStepClaim $claim, \Webong\WorkFlow\ValueObjects\StepResult $result): void;

    /**
     * Release a claim back to the queue (e.g., on worker crash or cancellation).
     *
     * @param FlowStepClaim $claim The claim to release.
     * @return void
     */
    public function release(FlowStepClaim $claim): void;

    /**
     * Mark a claimed step as failed and schedule a retry if applicable.
     *
     * @param FlowStepClaim $claim The claim that failed.
     * @param \Throwable $exception The exception that caused the failure.
     * @return void
     */
    public function fail(FlowStepClaim $claim, \Throwable $exception): void;

    /**
     * Cancel all pending claims for a run.
     *
     * @param string $runId The run ID to cancel.
     * @return void
     */
    public function cancelRun(string $runId): void;

    /**
     * Reconcile expired leases.
     *
     * Should be called periodically to handle claims whose lease has expired
     * without completion. Returns the number of claims recovered.
     *
     * @return int Number of claims recovered.
     */
    public function reconcile(): int;
}

/**
 * A claimed step ready for execution.
 *
 * The claim includes all context needed to execute the step, plus
 * metadata for idempotent execution and lease management.
 */
final readonly class FlowStepClaim
{
    /**
     * @param array<string, mixed> $input Resolved input bindings.
     * @param array<string, mixed> $context Run context.
     * @param array<string, mixed> $previousState Previous step state.
     * @param array<string, mixed> $metadata Claim metadata (attempt, lease expiry, etc.)
     */
    public function __construct(
        public string $claimId,
        public FlowRun $run,
        public FlowStepDefinition $step,
        public FlowState $state,
        public array $input,
        public array $context,
        public array $previousState,
        public array $metadata = [],
    ) {
        if ($this->claimId === '') {
            throw new \InvalidArgumentException('Claim ID must be non-empty.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'claim_id' => $this->claimId,
            'run' => $this->run->toArray(),
            'step' => $this->step->toArray(),
            'state' => $this->state->toArray(),
            'input' => $this->input,
            'context' => $this->context,
            'previous_state' => $this->previousState,
            'metadata' => $this->metadata,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            claimId: is_string($data['claim_id'] ?? null) ? $data['claim_id'] : '',
            run: \Webong\WorkFlow\ValueObjects\FlowRun::fromArray($data['run'] ?? []),
            step: \Webong\WorkFlow\ValueObjects\FlowStepDefinition::fromArray($data['step'] ?? []),
            state: \Webong\WorkFlow\ValueObjects\FlowState::fromArray($data['state'] ?? []),
            input: is_array($data['input'] ?? null) ? $data['input'] : [],
            context: is_array($data['context'] ?? null) ? $data['context'] : [],
            previousState: is_array($data['previous_state'] ?? null) ? $data['previous_state'] : [],
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}