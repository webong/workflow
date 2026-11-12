# Run workflows with the reference queue runtime

Use `Webong\WorkFlow\App\QueueScheduler` when a trusted host needs durable
queue execution without Laravel or Temporal. Its database owns queue runs;
RPC database/Redis projections do not control these runs.

```php
use Webong\WorkFlow\App\QueueScheduler;
use Webong\WorkFlow\App\QueueWorker;
use Webong\WorkFlow\ValueObjects\FlowRun;

$pdo = new PDO($dsn, $user, $password);
QueueScheduler::install($pdo, maxConcurrency: 4); // deployment step, once
$queue = new QueueScheduler($pdo);
$queue->start(new FlowRun('globally-unique-run-id', $definition), $context);
$worker = new QueueWorker($queue, $registeredExecutors);
$worker->tick(leaseSeconds: 300); // claims and executes one step
```

Install into an empty schema before starting workers. PostgreSQL, MySQL
(InnoDB), and SQLite are supported. The `workflow_queue_*` tables are separate
from existing RPC store tables. Use one dedicated PDO connection per process;
do not wrap runtime calls in another transaction. All workers share the
capacity stored during installation. Lease and retry times use the database
clock sampled after acquiring the mutation lock, so worker clock skew cannot
expire another worker early. The optional clock closure is for deterministic
tests and must not differ between production workers. Run IDs are unique across this queue
namespace; include tenant/subject scope in the ID when needed. Authenticate
and authorize host APIs before calling this runtime.

The reference implementation serializes mutations on one database mutex.
It favors correctness over high scheduling throughput; it scans run snapshots
when scheduling. Network calls, executors, and broker publication never run
inside that transaction. Multiple worker processes execute independent steps
concurrently, up to the shared capacity. Each process executes one step at a
time; a dependent step becomes eligible after every dependency completes.

## Try two workers

From a PHP 8.3 environment with `pdo_sqlite` and Composer dependencies:

```sh
php examples/queue-order.php init /tmp/order-queue.sqlite
php examples/queue-order.php work /tmp/order-queue.sqlite &
php examples/queue-order.php work /tmp/order-queue.sqlite &
wait
php examples/queue-order.php status /tmp/order-queue.sqlite
```

Inventory and payment overlap; shipping follows both. Each worker opens its
own database connection. The example uses local simulated effects and caps
idle sleeping at one second. It handles SIGTERM/SIGINT when `pcntl` is
available, finishing the current attempt before stopping.

## Broker delivery

For broker mode, a scheduler calls `claim($batchSize, $leaseSeconds)`. That
transaction persists RUNNING attempts, their opaque claim IDs, and
`work.ready` outbox events. A dispatcher publishes committed events:

```php
use Webong\WorkFlow\App\QueueOutboxDispatcher;

$dispatcher = new QueueOutboxDispatcher($queue,
    function (string $eventId, string $type, array $payload) use ($broker): void {
        $broker->publish($type, $payload, idempotencyKey: $eventId);
    },
);
$dispatcher->dispatch(limit: 100, leaseSeconds: 30, retrySeconds: 5);
```

A work consumer passes only `$payload['claim_id']` to `$worker->handle()`.
Definitions, inputs, context, and executors come from trusted server state.
`beginExecution()` atomically fences duplicate deliveries. A failed publisher
retains the event with backoff; a crashed publisher's delivery lease expires.
If publication succeeded before a crash, the same event ID is published again.
Consumers must deduplicate lifecycle events on event ID. A duplicate work
message cannot re-execute a live or finished attempt.

Direct polling workers can coexist with a broker consumer: both use the same
execution gate. A direct worker still leaves outbox events for audit/event
consumers; run the dispatcher if you need those events delivered. Configure
publisher timeouts shorter than its delivery lease. An expired publisher
cannot acknowledge a replacement publisher's lease.

## Attempts, retries and crashes

Attempts increment when claimed, even if a process crashes before executing.
Failure retries require `FlowRetryPolicy` with bounded attempts and
`idempotent: true`. Explicit `retriable: false` results always stop retries.
Retry deadlines are persisted in state and enforced before re-claiming.
`nextWakeup()` returns the next ready time, lease expiry, retry or deferred
deadline. A supervisor can sleep until then, capped by its external
notification/shutdown interval. No internal retry loop spins on a future
retry deadline. Outbox publication has its own delivery loop and persisted
backoff.

Crash recovery handles:

| Failure point | Recovery |
|---|---|
| Before claim transaction commits | No state/claim/outbox change survives |
| After commit, before publication | Durable `work.ready` remains available |
| After publication, before acknowledgement | Event is replayed with the same ID |
| After execution starts, before result commit | Lease expires; reconcile applies bounded idempotent retry or terminal unknown outcome |
| During completion/outbox persistence | Both roll back; the live claim can resubmit the result |

`reconcile()` expires claims and applies deferred deadlines. `claim()` also
runs reconciliation. Expired claims cannot complete, renew or release. A
fresh claim gets a new ID and attempt number. `renew($claim, $seconds)` can
extend a live lease; it also accepts the opaque claim ID from `_queue.claim_id`.
Heartbeat from a separate connection/process when an
executor may outlive its lease. Set provider timeouts below the lease if you
do not supply heartbeats. An expired effect may continue running externally;
fencing prevents its state write, not the effect itself.

Executors receive `_queue.claim_id` and `_queue.idempotency_key` through
`FlowStepInvocation::context`. The latter is stable for this run/step across
attempts. Pass it to the external provider or maintain a transactional effect
ledger. Queue delivery does not make external effects exactly once. Without
provider idempotency, do not declare a retry policy idempotent.

`release()` treats the outcome as unknown and uses the same conservative
retry policy. A non-idempotent unknown outcome becomes a terminal failure
with `error_code: claim_lost`, requiring operator/provider reconciliation.
Start an explicit new run after a host-approved recovery decision; do not
rewrite stored snapshots to replay effects.

`complete()` preserves result metadata, messages, safe errors, output presence,
and deferred deadlines. Repeating the same claim/result is idempotent;
conflicting results are rejected. Workers use `FlowStepExecution` for schema
hooks, invocation metadata and private failure reporting. Configure a
`FlowFailureReporter` there to receive exception details; public state gets a
safe error and correlation ID. `fail()` is the low-level safe fallback.

## Callbacks and cancellation

Use `get($runId)` for authoritative state, `completeDeferred($completion)`
for authenticated attempt-correlated callbacks, and `cancelRun($runId)` for
cancellation. Do not write an RPC projection to control a queue execution.
Deferred results remain waiting without re-running their executors. A plain
`pending()` result pauses that step until the host calls `resumeRun($runId)`;
it does not create a tight automatic execution loop. Callback
IDs are deduplicated by the existing transition contract. Deadline expiry
uses `deadlineReached()`, including successful OWS wait deadlines.
Cancellation fences live claims and prevents future claims; it cannot undo
an external effect already started.

The generic `QueuedFlowExecutionDriver` still accepts a host dispatch closure.
It does not automatically bind this runtime into RPC. A host integrating RPC
must route reads, callbacks and cancellation to this authority as well as
start requests; merely registering a queue starter is insufficient.

## Upgrade from the first reference scheduler

Replace `Services\QueueScheduler(PDO, FlowStateStore, ...)` with
`App\QueueScheduler(PDO)` and install the new dedicated tables. Import
`ValueObjects\FlowStepClaim`; its old nested declaration in the contract file
has moved to its PSR-4 path. `FlowQueueScheduler` remains framework-neutral.
The worker now accepts the scheduler plus registered executors and offers
`tick()` / `handle()`; it no longer creates demonstration tables on inclusion.

Old claim/outbox tables do not have enough state for a safe automatic upgrade.
Drain old workers, reconcile their in-flight external effects, retain old
state for audit, and start new runs explicitly. Do not copy uncertain claims
into the new tables. No production schema/data is modified by this change.

## Validation

```sh
make queue-test
```

This isolated Docker PHP 8.3 target runs the queue tests with SQLite,
PostgreSQL, MySQL, and real competing PHP worker processes. It also runs the
complete suite, PHPStan, and generated-contract checks. Tests cover rollback,
lease loss/renewal, retry limits/backoff, sibling-state merging, cancellation,
deferred outcomes, duplicate delivery, outbox replay and provider idempotency.
Stop its services with `docker compose -f compose.queue-test.yml down`.
