<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowStepClaim;

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
     * which the outcome must be reconciled under its retry/idempotency policy.
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
