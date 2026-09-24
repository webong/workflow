# WorkFlow

WorkFlow provides framework- and delivery-surface-agnostic flow definitions,
step execution, state evaluation, presentations, actions, and deferred
completion handling. It can be used by Laravel web applications, APIs, queues,
CLI commands, mobile backends, and other host runtimes.

## Core boundary

- The core package has no Laravel, database, Redis, HTTP, queue, or UI runtime
  dependency.
- Use `FlowStepDefinition` for step definitions. Do not introduce the former
  `StepDefinition` name in new code.
- `FlowRun`, `FlowDefinition`, `FlowState`, `FlowStepDefinition`, `StepResult`, and related
  value objects are framework-neutral and immutable.
- The host application owns HTTP calls, queue dispatch, authorization, event
  publication, and presentation-specific rendering.
- Persist state through `FlowStateStore`; use `AtomicFlowStateStore::mutate()`
  for read-modify-write transitions that may run concurrently.
- Create `FlowRun` with an explicit ID and wrap the subject's store in
  `RunScopedFlowStateStore`. Resume and complete using the saved definition
  snapshot; a new occurrence gets a new run ID.

## Defining and running a flow

Create a versioned `FlowDefinition` with `FlowStepDefinition` instances. Step
dependencies must refer to existing steps and definitions reject cycles. Use
`FlowEvaluator` to derive a state and `FlowRunner` with host-provided
`FlowStepExecutor` implementations to execute supported steps.

<code-snippet name="Define and run a flow" lang="php">
use Webong\WorkFlow\Services\FlowEvaluator;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;

$definition = new FlowDefinition(
    key: 'channel_setup',
    version: 1,
    steps: [
        new FlowStepDefinition(id: 'authorize', label: 'Authorize channel'),
    ],
);

$state = (new FlowEvaluator())->evaluate(
    $definition,
    (new FlowRun('channel-1-setup-1', $definition))->initialState(),
);

$state = (new FlowRunner())->run(
    definition: $definition,
    state: $state,
    context: new ArrayFlowContext(['channel_id' => 'channel-1']),
    executors: [$authorizeExecutor],
);
</code-snippet>

`FlowRunner` returns a new state. Persist it after execution and dispatch any
follow-up work through the host application's queue or process manager.

## Deferred completion

Return `StepResult::deferred()` when an external provider callback must finish a
step. Complete the step with `FlowDeferredCompletion` and
`FlowStateTransition::complete()`. Provide the run ID, step ID, original attempt,
and stable event ID; exact duplicates are ignored and conflicting/stale events
are rejected. Wrap the transition in
`AtomicFlowStateStore::mutate()` when callbacks can race.

Resume explicitly after recording completion. The runner leaves deferred work
waiting, respects non-retriable failures, and never resets a tracked run for
replay. Keep external effects outside mutation locks and use host execution
claims/outbox delivery when workers can overlap.

Supply `FlowActionAuthorizer` to `FlowActionDispatcher`. Use
`FlowFailureReporter` for private exception diagnostics; step error messages
and metadata are public-facing values.

## Optional Laravel integration

The Laravel provider and adapters are optional and live under `ext/Laravel`. In
a Laravel host:

1. Publish the WorkFlow config and migrations, then run `php artisan migrate`.
2. Inject `FlowStateStoreFactory`; create a store using a neutral
   `FlowStateSubject` made from `$model->getMorphClass()` and `$model->getKey()`.
   Wrap it in `RunScopedFlowStateStore` with the selected run ID.
3. Use the database driver for polymorphic Eloquent state or the Redis driver
   for lock-protected atomic state.
4. Keep Eloquent `Model` and morph APIs inside the Laravel adapter boundary;
   never add them to core contracts.
5. Treat Redis TTL as opt-in because workflow state is authoritative by default.

The published database migration provides the polymorphic subject columns and
the unique subject/flow key needed to prevent duplicate state rows. Database
mutations use row locks; Redis mutations use an atomic distributed lock.

## Optional Temporal integration

The repository includes an optional adapter under `ext/Temporal`. Install
`temporal/sdk` only in a host that uses Temporal. `TemporalFlowWorkflow` owns
deterministic orchestration, while `TemporalFlowActivity` delegates side
effects to host-provided `FlowStepExecutor` instances. Use
`TemporalFlowCompletion` as the serialized payload for the workflow's
completion Signal. Temporal event history is authoritative; Laravel database
and Redis stores are projections only for this adapter. The adapter disables
Temporal's default Activity retries so `FlowRetryPolicy` remains authoritative.
Laravel hosts may use `config('work-flow.temporal')` for the Temporal address,
namespace, task queue, and feature flag; the package does not create the SDK
client or worker. Use `config('work-flow.execution.default')` only as the
host's initial routing choice; register the concrete drivers in application
bootstrap and persist the selected driver with the execution.

## Testing

Use the package's PHP 8.3 Docker environment for authoritative validation:

<code-snippet name="Run WorkFlow's test stack" lang="shell">
make docker-test
make docker-down
</code-snippet>

The stack exercises the unit suite, PostgreSQL adapter, Redis adapter, PHPStan,
and generated TypeScript contracts. Do not rely on a newer host PHP version for
release validation.
