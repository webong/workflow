# Use WorkFlow in a PHP application

WorkFlow is a PHP library, not a server by default. Your application owns the
business operation, the state store, and when the flow runs again. WorkFlow
decides which steps are eligible, records their outcomes, and evaluates the
overall state.

## Start with a working example

Run [`examples/order-approval.php`](../examples/order-approval.php) from this
repository, or copy it into a PHP 8.3+ Composer project that has
`webong/workflow` installed. It models two steps:

1. `approve` waits for an external approval event.
2. `notify` becomes eligible only after `approve` completes.

The example prints the state after starting, after receiving the approval,
and after explicitly resuming the runner. Nothing is scheduled in the
background for you.

## Put it in your application

1. Define a `FlowDefinition` with stable step IDs. IDs are persisted, so
   changing an ID is a state migration, not just a label change. Set
   `dependsOn` when a step must wait for another.
2. Implement `FlowStepExecutor` for each domain operation. `supports()` chooses
   the step; `execute()` returns a `StepResult`. Return `completed()`,
   `failed()`, `skipped()`, or `deferred()` as appropriate. Do not put request
   or framework objects in the definition or persisted state.
3. Create `new FlowRun($runId, $definition)` and begin with its
   `initialState()`. Wrap your subject's store in `RunScopedFlowStateStore`
   using the same run ID. Call `FlowRunner::run()` and save its returned state.
   On resume, use the saved `$state->run->definition`. The runner does not
   save state automatically.
4. Show the resulting status and step states in your API, CLI, or UI. Those
   surfaces can all read the same serialized state.

`InMemoryFlowStateStore` in the example is for learning and tests: its data
disappears when the PHP process exits. For durable state, use a
`FlowStateStoreFactory` implementation. Laravel database and Redis adapters
are described in [the Laravel guide](laravel.md); the standalone service has
a PDO PostgreSQL store in [`mod/`](../mod/README.md).

## Finish a deferred step

`StepResult::deferred()` leaves the step pending. When your provider webhook,
operator action, or background task returns, build a `FlowDeferredCompletion`
with the same flow key, run ID, step ID, attempt number, a stable
event/idempotency key, and a terminal `StepResult`. Apply it with
`FlowStateTransition::complete()` inside
`AtomicFlowStateStore::mutate()` so concurrent callbacks see the same state.

The transition ignores an exact duplicate. Reusing an event ID with a different
result, or targeting the wrong run or attempt, is rejected. It updates
the state, but does **not** automatically run newly eligible steps. Your host
must call `FlowRunner::run()` again (or dispatch its own job) to resume the
flow. The executable example shows both calls.

Avoid external side effects while holding a database transaction or Redis
lock. For a slow or irreversible operation, dispatch it from your host and
complete the step when its result arrives. The included runners and stores do
not provide an outbox or exactly-once external delivery.

## Execution driver versus state store

These are separate decisions:

| Question | Choices supplied here | Owner |
| --- | --- | --- |
| Where does state live? | In-memory; optional Laravel database/Redis; standalone PDO PostgreSQL | Your host chooses a store |
| Where does the run execute? | Inline; optional Laravel queue or Temporal adapters | Your host registers a driver and its worker/client |

`FlowExecutionDispatcher` selects a registered `FlowExecutionDriver` by name
and records that name in the state snapshot. `InlineFlowExecutionDriver` runs
`FlowRunner` immediately. `LaravelQueueFlowExecutionDriver` and
`TemporalFlowExecutionDriver` accept host-provided dispatch callbacks; merely
installing this package does not create jobs, workers, or a Temporal client.
The standalone JSON-RPC module currently exposes only inline execution.

## Useful types

- `FlowDefinition` and `FlowStepDefinition`: what the flow *is*.
- `FlowRun`: one occurrence with an explicit ID and pinned definition snapshot.
- `FlowState` and `StepState`: what has *happened* in that run.
- `FlowStateSubject`: framework-neutral polymorphic owner (`type`, `id`).
- `FlowContext`: host-provided data available while executing, not durable
  state by itself.
- `FlowPresentation` and `FlowAction`: optional UI-neutral descriptions; the
  host still authorizes and performs actions.
- `FlowStateSerializer`: durable array representation used by stores.
- `FlowStateMigrationRunner`: explicit upgrades when persisted state schema
  changes.

Definitions validate duplicate step IDs, unknown dependencies, and cycles.
Step retry policy governs retry eligibility and backoff; your host remains
responsible for scheduling another run.

## Save and find a particular run

```php
use Webong\WorkFlow\Services\RunScopedFlowStateStore;
use Webong\WorkFlow\ValueObjects\FlowRun;

$run = new FlowRun('channel-42-setup-1', $definition);
$store = new RunScopedFlowStateStore($factory->for($subject), $run->id);
$store->put($definition->key, $run->initialState());

$state = $store->get($definition->key);
// Use $state->run->definition when executing or completing this saved run.
```

This initialization example assumes one caller. For concurrent starts, use
`mutate()` to insert only when the current state is null, then let your host
claim execution. Keep external calls outside the mutation callback. The
standalone RPC service implements a persisted execution claim for its inline
runner; core integrations supply their own claim/outbox strategy.

Within an executor, `$context->get('_workflow')` contains `flow_key`, `run_id`,
`step_id`, and `attempt`. Use these to correlate a provider callback and to
construct a stable external idempotency key. The host must authenticate the
callback; knowing a run ID does not grant permission to complete it.

## Show actions and report failures

Pass a `FlowActionAuthorizer` alongside your `FlowActionRegistry` when creating
`FlowActionDispatcher`. Its `allows()` method must check the actor, subject,
and action against your host's policy before the registered handler runs.
Render action labels and payloads in the host's UI or CLI.

Unexpected executor exceptions produce `Flow step failed.` and metadata with
`error_code` and `correlation_id`. Pass `new FlowStepExecution($reporter)` to
the runner's `execution` argument, where `$reporter` implements
`FlowFailureReporter`. The reporter receives the original exception, correlation
ID, flow key, step ID, and run ID for private diagnostics. The default reporter
does nothing. `StepResult::failed()` is for intentionally public business
errors; do not pass exception messages or credentials into it.

See [run lifecycle](run-lifecycle.md) for retries, cancellation, retention,
version changes, and the guarantees your host must supply.
