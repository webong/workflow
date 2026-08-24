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
- `FlowDefinition`, `FlowState`, `FlowStepDefinition`, `StepResult`, and related
  value objects are framework-neutral and immutable.
- The host application owns HTTP calls, queue dispatch, authorization, event
  publication, and presentation-specific rendering.
- Persist state through `FlowStateStore`; use `AtomicFlowStateStore::mutate()`
  for read-modify-write transitions that may run concurrently.

## Defining and running a flow

Create a versioned `FlowDefinition` with `FlowStepDefinition` instances. Step
dependencies must refer to existing steps and definitions reject cycles. Use
`FlowEvaluator` to derive a state and `FlowRunner` with host-provided
`FlowStepExecutor` implementations to execute supported steps.

<code-snippet name="Define and run a flow" lang="php">
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Services\FlowEvaluator;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
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
    new FlowState(FlowStatus::PENDING),
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
`FlowStateTransition::complete()`. Always provide a stable idempotency key;
duplicate callbacks are ignored. Wrap the transition in
`AtomicFlowStateStore::mutate()` when callbacks can race.

## Optional Laravel integration

The Laravel provider and adapters are optional. In a Laravel host:

1. Publish the WorkFlow config and migrations, then run `php artisan migrate`.
2. Inject `FlowStateStoreFactory`; create a store using a neutral
   `FlowStateSubject` made from `$model->getMorphClass()` and `$model->getKey()`.
3. Use the database driver for polymorphic Eloquent state or the Redis driver
   for lock-protected atomic state.
4. Keep Eloquent `Model` and morph APIs inside the Laravel adapter boundary;
   never add them to core contracts.
5. Treat Redis TTL as opt-in because workflow state is authoritative by default.

The published database migration provides the polymorphic subject columns and
the unique subject/flow key needed to prevent duplicate state rows. Database
mutations use row locks; Redis mutations use an atomic distributed lock.

## Testing

Use the package's PHP 8.3 Docker environment for authoritative validation:

<code-snippet name="Run WorkFlow's test stack" lang="shell">
make docker-test
make docker-down
</code-snippet>

The stack exercises the unit suite, PostgreSQL adapter, Redis adapter, PHPStan,
and generated TypeScript contracts. Do not rely on a newer host PHP version for
release validation.
