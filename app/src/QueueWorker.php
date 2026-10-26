<?php

declare(strict_types=1);

namespace Webong\WorkFlow\App;

use Closure;
use PDO;
use Webong\WorkFlow\Contracts\FlowQueueScheduler;
use Webong\WorkFlow\Contracts\FlowStateStore;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Services\QueueScheduler;
use Webong\WorkFlow\Services\DefaultFlowStateSerializer;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Reference queue worker implementation.
 *
 * Demonstrates how to consume FlowReadySet via FlowQueueScheduler,
 * execute steps with proper claim lifecycle, and handle concurrency.
 *
 * Run with: php app/src/QueueWorker.php
 */
final class QueueWorker
{
    private FlowQueueScheduler $scheduler;
    private array $executors = [];
    private int $maxClaims = 5;
    private int $leaseSeconds = 300;
    private int $pollInterval = 5;
    private bool $running = false;
    private ?Closure $logger = null;

    public function __construct(
        PDO $pdo,
        FlowStateStore $store,
        ?Closure $logger = null,
    ) {
        $this->logger = $logger ?? static fn (string $msg): void => fwrite(STDERR, $msg . PHP_EOL);
        $this->scheduler = new QueueScheduler(
            pdo: $pdo,
            store: $store,
            maxConcurrency: 10,
        );
    }

    public function addExecutor(FlowStepExecutor $executor): self
    {
        $this->executors[] = $executor;
        return $this;
    }

    public function setMaxClaims(int $maxClaims): self
    {
        $this->maxClaims = max(1, $maxClaims);
        return $this;
    }

    public function setLeaseSeconds(int $seconds): self
    {
        $this->leaseSeconds = max(10, $seconds);
        return $this;
    }

    public function setPollInterval(int $seconds): self
    {
        $this->pollInterval = max(1, $seconds);
        return $this;
    }

    public function run(): void
    {
        $this->running = true;
        ($this->logger)("Queue worker started (pid: " . getmypid() . ")");

        // Register signal handlers for graceful shutdown
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
        pcntl_signal(SIGINT, [$this, 'shutdown']);

        while ($this->running) {
            $this->tick();
            if ($this->running) {
                sleep($this->pollInterval);
            }
        }

        ($this->logger)("Queue worker stopped");
    }

    public function shutdown(): void
    {
        ($this->logger)("Shutdown signal received");
        $this->running = false;
    }

    private function tick(): void
    {
        // Reconcile expired leases
        $recovered = $this->scheduler->reconcile();
        if ($recovered > 0) {
            ($this->logger)("Reconciled {$recovered} expired claims");
        }

        // Claim ready steps
        $claims = $this->scheduler->claim($this->maxClaims, $this->leaseSeconds);

        if ($claims === []) {
            return;
        }

        ($this->logger)("Claimed " . count($claims) . " step(s)");

        foreach ($claims as $claim) {
            if (! $this->running) {
                break;
            }
            $this->executeClaim($claim);
        }
    }

    private function executeClaim(\Webong\WorkFlow\ValueObjects\FlowStepClaim $claim): void
    {
        $step = $claim->step;
        $attempt = $claim->metadata['attempt'] ?? 1;

        ($this->logger)("Executing step {$step->id} (attempt {$attempt}) for run {$claim->run->id}");

        // Find executor
        $executor = null;
        foreach ($this->executors as $candidate) {
            if ($candidate->supports($step)) {
                $executor = $candidate;
                break;
            }
        }

        if ($executor === null) {
            ($this->logger)("No executor for step {$step->id}, releasing claim");
            $this->scheduler->release($claim);
            return;
        }

        // Create invocation
        $invocation = new \Webong\WorkFlow\ValueObjects\FlowStepInvocation(
            step: $step,
            input: new \Webong\WorkFlow\ValueObjects\FlowStepInput($claim->input),
            context: new \Webong\WorkFlow\ValueObjects\ArrayFlowContext($claim->context),
            previous: StepState::fromArray($claim->previousState),
            flowKey: $claim->run->definition->key,
            runId: $claim->run->id,
            attempt: $attempt,
        );

        try {
            $result = $executor->execute($invocation);
            ($this->logger)("Step {$step->id} completed with status {$result->status->value}");

            match ($result->status) {
                \Webong\WorkFlow\Enums\FlowStepStatus::COMPLETED,
                \Webong\WorkFlow\Enums\FlowStepStatus::SKIPPED => $this->scheduler->complete($claim, $result),
                \Webong\WorkFlow\Enums\FlowStepStatus::FAILED => $this->scheduler->fail($claim, new \RuntimeException($result->message ?? 'Step failed')),
                \Webong\WorkFlow\Enums\FlowStepStatus::PENDING => $this->scheduler->release($claim),
                default => $this->scheduler->release($claim),
            };
        } catch (\Throwable $e) {
            ($this->logger)("Step {$step->id} threw exception: " . $e->getMessage());
            $this->scheduler->fail($claim, $e);
        }
    }
}

/**
 * Example executors demonstrating concurrent execution.
 */
class ProcessOrderExecutor implements FlowStepExecutor
{
    public function supports(FlowStepDefinition $step): bool
    {
        return $step->id === 'process_order';
    }

    public function execute(\Webong\WorkFlow\ValueObjects\FlowStepInvocation $invocation): StepResult
    {
        $orderId = $invocation->input->get('order_id') ?? 'unknown';
        // Simulate processing
        usleep(100_000); // 100ms

        return StepResult::completed(
            "Order {$orderId} processed",
            output: \Webong\WorkFlow\ValueObjects\StepOutput::from(['processed' => true, 'order_id' => $orderId])
        );
    }
}

class VerifyPaymentExecutor implements FlowStepExecutor
{
    public function supports(FlowStepDefinition $step): bool
    {
        return $step->id === 'verify_payment';
    }

    public function execute(\Webong\WorkFlow\ValueObjects\FlowStepInvocation $invocation): StepResult
    {
        $orderId = $invocation->input->get('order_id') ?? 'unknown';
        // Simulate verification
        usleep(150_000); // 150ms

        return StepResult::completed(
            "Payment for {$orderId} verified",
            output: \Webong\WorkFlow\ValueObjects\StepOutput::from(['verified' => true, 'order_id' => $orderId])
        );
    }
}

class ShipOrderExecutor implements FlowStepExecutor
{
    public function supports(FlowStepDefinition $step): bool
    {
        return $step->id === 'ship_order';
    }

    public function execute(\Webong\WorkFlow\ValueObjects\FlowStepInvocation $invocation): StepResult
    {
        $orderId = $invocation->input->get('order_id') ?? 'unknown';
        $processed = $invocation->input->get('processed') ?? false;
        $verified = $invocation->input->get('verified') ?? false;

        if (! $processed || ! $verified) {
            return StepResult::failed("Dependencies not satisfied for {$orderId}");
        }

        // Simulate shipping
        usleep(50_000); // 50ms

        return StepResult::completed(
            "Order {$orderId} shipped",
            output: \Webong\WorkFlow\ValueObjects\StepOutput::from(['shipped' => true, 'order_id' => $orderId])
        );
    }
}

/**
 * Entry point for running the worker.
 */
if (PHP_SAPI === 'cli' && ! isset($argv[0])) {
    // Running as included file
} elseif (PHP_SAPI === 'cli' && basename($argv[0]) === 'QueueWorker.php') {
    // Create a demo run
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create schema
    $pdo->exec(<<<'SQL'
CREATE TABLE workflow_runs (
    id TEXT PRIMARY KEY,
    flow_key TEXT NOT NULL,
    version INTEGER NOT NULL,
    subject_type TEXT NOT NULL,
    subject_id TEXT NOT NULL,
    definition_snapshot TEXT NOT NULL,
    status TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE workflow_step_claims (
    claim_id TEXT PRIMARY KEY,
    run_id TEXT NOT NULL,
    step_id TEXT NOT NULL,
    claimant TEXT NOT NULL,
    claimed_at INTEGER NOT NULL,
    lease_expires_at INTEGER NOT NULL,
    payload TEXT NOT NULL,
    status TEXT NOT NULL,
    error TEXT,
    next_retry_at INTEGER,
    UNIQUE(run_id, step_id, status) WHERE status = 'claimed'
);

CREATE TABLE workflow_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    payload TEXT NOT NULL,
    created_at TEXT NOT NULL,
    processed_at TEXT
);
SQL);

    $store = new \Webong\WorkFlow\Services\InMemoryFlowStateStore();
    $serializer = new \Webong\WorkFlow\Services\DefaultFlowStateSerializer();

    $worker = new QueueWorker($pdo, $store)
        ->addExecutor(new ProcessOrderExecutor())
        ->addExecutor(new VerifyPaymentExecutor())
        ->addExecutor(new ShipOrderExecutor())
        ->setMaxClaims(5)
        ->setLeaseSeconds(300)
        ->setPollInterval(2);

    // Create a demo flow definition
    $definition = new FlowDefinition('order-processing', [
        new FlowStepDefinition('process_order', 'Process Order'),
        new FlowStepDefinition('verify_payment', 'Verify Payment'),
        new FlowStepDefinition('ship_order', 'Ship Order', dependsOn: ['process_order', 'verify_payment']),
    ], version: 1);

    $subject = new FlowStateSubject('order', 'order-123');
    $run = new FlowRun('run-1', $definition);
    $state = $run->initialState();
    $state = $state->withContext(['order_id' => 'order-123', 'amount' => 99.99]);
    $store->put('run-1', $state);

    echo "Starting queue worker demo...\n";
    echo "Flow: {$definition->key} v{$definition->version}\n";
    echo "Run: {$run->id}\n";
    echo "Subject: {$subject->type}/{$subject->id}\n\n";

    // Run a few ticks to demonstrate
    for ($i = 0; $i < 3 && $worker->running; $i++) {
        $worker->tick();
        sleep(1);
    }

    // Show final state
    $finalState = $store->get('run-1');
    echo "\nFinal state:\n";
    echo "  Status: {$finalState->status->value}\n";
    foreach ($finalState->steps as $id => $step) {
        echo "  Step {$id}: {$step->status->value} (attempts: {$step->attempts})\n";
        if ($step->output !== null) {
            echo "    Output: " . json_encode($step->output->value) . "\n";
        }
    }
}