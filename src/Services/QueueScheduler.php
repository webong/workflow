<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use Webong\WorkFlow\Contracts\FlowQueueScheduler;
use Webong\WorkFlow\Contracts\FlowStateStore;
use Webong\WorkFlow\Contracts\FlowStepClaim;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Services\FlowReadySet;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Database-backed reference queue scheduler.
 *
 * Uses a claim table with atomic compare-and-set operations for step claiming.
 * Includes an outbox for reliable queue publication after state commit.
 *
 * The schema requires:
 * - workflow_step_claims: claim_id, run_id, step_id, claimant, claimed_at, lease_expires_at, payload, status
 * - workflow_outbox: id, event_type, payload, created_at, processed_at
 *
 * This is a reference implementation; hosts may provide their own optimized
 * version (e.g., using Redis, Kafka, or a dedicated queue system).
 */
final class QueueScheduler implements FlowQueueScheduler
{
    public function __construct(
        private PDO $pdo,
        private FlowStateStore $store,
        private FlowReadySet $readySet = new FlowReadySet(),
        private FlowInputResolver $inputResolver = new FlowInputResolver(),
        private int $maxConcurrency = 10,
        private ?Closure $idGenerator = null,
        private ?Closure $runIdProvider = null,
        private ?Closure $contextProvider = null,
    ) {
        if ($this->maxConcurrency < 1) {
            throw new InvalidArgumentException('maxConcurrency must be at least 1');
        }
        $this->idGenerator ??= static fn (): string => bin2hex(random_bytes(16));
    }

    public function claim(int $maxClaims, int $leaseSeconds): array
    {
        $maxClaims = min($maxClaims, $this->maxConcurrency);
        if ($maxClaims < 1) {
            return [];
        }

        $claims = [];
        $now = time();
        $leaseExpiresAt = $now + $leaseSeconds;

        // Start transaction for atomic claim + state update
        $this->pdo->beginTransaction();
        try {
            // Find runs with ready steps
            $runIds = $this->findRunnableRunIds();
            if ($runIds === []) {
                $this->pdo->commit();
                return [];
            }

            foreach ($runIds as $runId) {
                if (count($claims) >= $maxClaims) {
                    break;
                }

                $runClaims = $this->claimForRun($runId, $leaseExpiresAt, $maxClaims - count($claims));
                $claims = array_merge($claims, $runClaims);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $claims;
    }

    public function complete(FlowStepClaim $claim, StepResult $result): void
    {
        $this->pdo->beginTransaction();
        try {
            // Get current state from store (may have been updated by other completions)
            $state = $this->store->get($claim->run->id);
            if ($state === null) {
                throw new RuntimeException("Run {$claim->run->id} not found");
            }

            // Update claim status
            $stmt = $this->pdo->prepare(
                'UPDATE workflow_step_claims SET status = ?, output = ? WHERE claim_id = ? AND status = ?'
            );
            $stmt->execute(['completed', json_encode($result->toArray()), $claim->claimId, 'claimed']);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException("Claim {$claim->claimId} not found or already processed");
            }

            // Update workflow state using current state from store
            $outputValue = $result->output instanceof \Webong\WorkFlow\ValueObjects\StepOutput
                ? $result->output->value
                : null;
            $state = $state->withStep($claim->step->id, StepState::fromArray([
                'status' => $result->status->value,
                'output' => $outputValue,
                'attempts' => $claim->previousState['attempts'] ?? 1,
                'retriable' => $result->retriable,
                'updatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
            ]));

            $state = (new \Webong\WorkFlow\Services\FlowEvaluator())->evaluate($claim->run->definition, $state);
            $this->store->put($claim->run->id, $state);

            // Write outbox events for any side effects
            $this->writeOutbox($claim, $result, $state);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function release(FlowStepClaim $claim): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE workflow_step_claims SET status = ? WHERE claim_id = ? AND status = ?'
            );
            $stmt->execute(['released', $claim->claimId, 'claimed']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function fail(FlowStepClaim $claim, Throwable $exception): void
    {
        $this->pdo->beginTransaction();
        try {
            $step = $claim->step;
            $previous = StepState::fromArray($claim->previousState);
            $attempts = $previous->attempts + 1;

            $retriable = $previous->retriable !== null ? $previous->retriable : $step->retriable;
            $retryPolicy = $step->retryPolicy;
            $canRetry = $retriable && $retryPolicy?->canRetry($attempts) === true;

            $status = $canRetry ? 'released' : 'failed';
            $nextRetryAt = $canRetry ? time() + $retryPolicy->backoffSeconds : null;

            $stmt = $this->pdo->prepare(
                'UPDATE workflow_step_claims SET status = ?, error = ?, next_retry_at = ? WHERE claim_id = ? AND status = ?'
            );
            $stmt->execute([
                $status,
                json_encode(['message' => $exception->getMessage(), 'code' => $exception->getCode()]),
                $nextRetryAt,
                $claim->claimId,
                'claimed',
            ]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException("Claim {$claim->claimId} not found or already processed");
            }

            if (!$canRetry) {
                // Update workflow state to blocked/failed
                $state = $claim->state->withStep($claim->step->id, new StepState(
                    FlowStepStatus::FAILED,
                    output: StepOutput::null(),
                    attempts: $attempts,
                    retriable: false,
                    updatedAt: (new DateTimeImmutable())->format(DATE_ATOM),
                    metadata: ['error' => $exception->getMessage()],
                ));
                $state = (new \Webong\WorkFlow\Services\FlowEvaluator())->evaluate($claim->run->definition, $state);
                $this->store->put($claim->run->id, $state);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function cancelRun(string $runId): void
    {
        $this->pdo->beginTransaction();
        try {
            // Release all claimed steps for this run
            $stmt = $this->pdo->prepare(
                'UPDATE workflow_step_claims SET status = ? WHERE run_id = ? AND status = ?'
            );
            $stmt->execute(['released', $runId, 'claimed']);

            // Update workflow state to cancelled
            $state = $this->store->get($runId);
            if ($state !== null) {
                $state = new FlowState(
                    FlowStatus::CANCELLED,
                    steps: $state->steps,
                    currentStep: $state->currentStep,
                    failedSteps: $state->failedSteps,
                    canRetryStep: $state->canRetryStep,
                    message: $state->message,
                    metadata: $state->metadata,
                    version: $state->version,
                    run: $state->run,
                );
                $this->store->put($runId, $state);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function reconcile(): int
    {
        $this->pdo->beginTransaction();
        try {
            $now = time();

            // Find expired claims
            $stmt = $this->pdo->prepare(
                'SELECT claim_id FROM workflow_step_claims WHERE status = ? AND lease_expires_at < ?'
            );
            $stmt->execute(['claimed', $now]);
            $expired = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($expired !== []) {
                $placeholders = str_repeat('?,', count($expired) - 1) . '?';
                $stmt = $this->pdo->prepare(
                    "UPDATE workflow_step_claims SET status = 'released' WHERE claim_id IN ({$placeholders}) AND status = 'claimed'"
                );
                $stmt->execute($expired);
            }

            $this->pdo->commit();
            return count($expired);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<string> Run IDs that have ready steps.
     */
    private function findRunnableRunIds(): array
    {
        // If a run ID provider is configured, use it (e.g., for testing with InMemoryFlowStateStore)
        if ($this->runIdProvider !== null) {
            return ($this->runIdProvider)();
        }

        // Query the database for runs with pending/running status
        // This is a simplified implementation. In production, you'd want
        // a more efficient query (e.g., materialized view of runnable runs).
        $stmt = $this->pdo->query(
            'SELECT id FROM workflow_runs WHERE status IN (\'pending\', \'running\', \'attention\')'
        );
        if ($stmt === false) {
            return [];
        }
        /** @var list<string> $result */
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $result;
    }

    /**
     * @return list<FlowStepClaim>
     */
    private function claimForRun(string $runId, int $leaseExpiresAt, int $maxClaims): array
    {
        $state = $this->store->get($runId);
        if ($state === null) {
            return [];
        }

        // Definition is stored in the run snapshot
        $definition = $state->run !== null ? $state->run->definition : new FlowDefinition('', []);
        $readySteps = $this->readySet->steps($definition, $state);
        if ($readySteps === []) {
            return [];
        }

        // Get context for this run (if provider is configured)
        $context = [];
        if ($this->contextProvider !== null) {
            /** @var Closure $contextProvider */
            $contextProvider = $this->contextProvider;
            $context = $contextProvider($runId);
        }

        $claims = [];
        $claimant = gethostname() . '-' . getmypid();

        foreach ($readySteps as $step) {
            if (count($claims) >= $maxClaims) {
                break;
            }

            // Check if already claimed
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM workflow_step_claims WHERE run_id = ? AND step_id = ? AND status = ?'
            );
            $stmt->execute([$runId, $step->id, 'claimed']);
            if ($stmt->fetch()) {
                continue; // Already claimed
            }

            // Resolve input bindings
            $input = $this->inputResolver->resolve(
                new \Webong\WorkFlow\Services\NullFlowEventSink(),
                $definition->key,
                $step,
                $state,
                new \Webong\WorkFlow\ValueObjects\ArrayFlowContext($context),
            )->all();

            $claimId = ($this->idGenerator ?? static fn (): string => bin2hex(random_bytes(16)))();
            $previous = $state->steps[$step->id] ?? new StepState();

            // Insert claim atomically
            $stmt = $this->pdo->prepare(
                'INSERT INTO workflow_step_claims (claim_id, run_id, step_id, claimant, claimed_at, lease_expires_at, payload, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $payload = json_encode([
                'input' => $input,
                'context' => $context,
                'previous_state' => $previous->toArray(),
                'metadata' => ['attempt' => $previous->attempts + 1],
            ]);

            try {
                $stmt->execute([
                    $claimId, $runId, $step->id, $claimant,
                    time(), $leaseExpiresAt, $payload, 'claimed'
                ]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') { // Unique constraint violation
                    continue; // Race condition - another worker claimed it
                }
                throw $e;
            }

            $run = $state->run ?? new FlowRun($runId, new FlowDefinition('', []));
            $claims[] = new FlowStepClaim(
                claimId: $claimId,
                run: $run,
                step: $step,
                state: $state,
                input: $input,
                context: [],
                previousState: $previous->toArray(),
                metadata: ['attempt' => $previous->attempts + 1, 'lease_expires_at' => $leaseExpiresAt],
            );
        }

        return $claims;
    }

    private function writeOutbox(FlowStepClaim $claim, StepResult $result, FlowState $newState): void
    {
        // Write step completion event
        $stmt = $this->pdo->prepare(
            'INSERT INTO workflow_outbox (event_type, payload, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            'step.completed',
            json_encode([
                'run_id' => $claim->run->id,
                'step_id' => $claim->step->id,
                'result' => $result->toArray(),
                'state' => $newState->toArray(),
            ]),
            (new DateTimeImmutable())->format(DATE_ATOM),
        ]);

        // If workflow completed, write completion event
        if (in_array($newState->status, [FlowStatus::COMPLETED, FlowStatus::BLOCKED, FlowStatus::CANCELLED], true)) {
            // Get workflow output from the last completed step or context
            $workflowOutput = null;
            foreach ($newState->steps as $stepState) {
                if ($stepState->output !== null) {
                    $workflowOutput = $stepState->output->value;
                }
            }
            $stmt->execute([
                'workflow.completed',
                json_encode([
                    'run_id' => $claim->run->id,
                    'status' => $newState->status->value,
                    'output' => $workflowOutput,
                ]),
                (new DateTimeImmutable())->format(DATE_ATOM),
            ]);
        }
    }
}