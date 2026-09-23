# WorkFlow

WorkFlow helps an application track a multi-step process: which steps are
ready, waiting, completed, or failed. For example, an order approval can wait
for a human decision before notifying a customer. The same state can be read
from an API, CLI, mobile app, or GUI.

The package is `webong/workflow` (PHP 8.3+). Its core does not choose a web
framework, state store, queue, or UI. Your application supplies the business
steps and decides when to run or resume them.

## Choose how to use it

| You want to... | Start here |
| --- | --- |
| Run flows inside any PHP application or CLI | [PHP guide](docs/php-library.md) |
| Persist state in a Laravel database or Redis | [Laravel guide](docs/laravel.md) |
| Call flows over JSON-RPC from a trusted backend | [Standalone Go/FrankenPHP guide](mod/README.md) |
| Run long-lived flows through Temporal | [Temporal guide](docs/temporal.md) |
| Work on this package | [Development and PHP 8.3 tests](docs/development.md) |

## See one flow work

The executable [order approval example](examples/order-approval.php) starts a
flow, waits for an approval callback, then resumes to notify the customer.
From a fresh checkout, run it in the repository's PHP 8.3 container:

```sh
docker compose -f docker-compose.test.yml run --rm php sh -lc 'composer update --no-interaction --prefer-dist && php examples/order-approval.php'
docker compose -f docker-compose.test.yml down
```

If you already installed Composer dependencies with PHP 8.3+, you can instead
run `php examples/order-approval.php` locally.

It prints:

```text
After start: running; approve: pending
After callback: running; approve: completed
After resume: completed; notify: completed
```

The example uses memory for clarity; it loses state when the process exits.
In a real app, replace that store with a durable one and have your webhook,
job, or action apply the deferred completion. See the [PHP guide](docs/php-library.md)
for that handoff.

## Install in an application

```sh
composer require webong/workflow
```

Then define a `FlowDefinition`, provide `FlowStepExecutor` implementations,
run with `FlowRunner`, and persist the returned `FlowState`. The runner does
not create a queue job, save state, or resume itself. If you only need remote
calls, deploy the separate [`mod/` service](mod/README.md) instead of exposing
the PHP library directly to mobile or CLI users.

## API reference

The examples below show the lower-level pieces. For a first integration,
start with the complete example and one of the guides above.

## Define and evaluate a flow

Create a versioned definition from immutable step value objects. Dependencies
must refer to existing steps and cycles are rejected when the definition is
constructed:

```php
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Services\FlowEvaluator;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRetryPolicy;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;

$definition = new FlowDefinition(
    key: 'channel_setup',
    version: 1,
    steps: [
        new FlowStepDefinition(
            id: 'authorize',
            label: 'Authorize the channel',
            retryPolicy: new FlowRetryPolicy(maxAttempts: 3, backoffSeconds: 30),
        ),
        new FlowStepDefinition(
            id: 'verify',
            label: 'Verify the channel',
            dependsOn: ['authorize'],
        ),
    ],
);

$state = (new FlowEvaluator())->evaluate(
    $definition,
    new FlowState(FlowStatus::PENDING),
);
```

`FlowEvaluator` is pure: it derives the current flow status from a definition
and a previously stored `FlowState`. The host decides where definitions and
states come from and where the returned state is persisted.

## Execute supported steps

Implement `FlowStepExecutor` for domain operations. WorkFlow does not perform
HTTP calls, dispatch jobs, or choose a queue driver for you:

```php
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

$executor = new class implements FlowStepExecutor {
    public function supports(FlowStepDefinition $step): bool
    {
        return $step->id === 'authorize';
    }

    public function execute(
        FlowStepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepResult {
        // Call the host application's provider here.
        return StepResult::completed('Channel authorized.');
    }
};

$state = (new FlowRunner())->run(
    definition: $definition,
    state: $state,
    context: new ArrayFlowContext(['channel_id' => 'channel-1']),
    executors: [$executor],
);
```

The runner respects dependencies, retry policy, completed/skipped steps, and
retry timestamps. It returns a new immutable state; persist that state and
dispatch any host-owned follow-up work after the call.

## Select an execution driver

Execution routing is separate from state storage. The core dispatcher accepts
named drivers, while adapters decide how a selected runtime is started:

- `inline` uses `InlineFlowExecutionDriver` and runs `FlowRunner` immediately.
- `queue` uses `LaravelQueueFlowExecutionDriver`; the host supplies the queue
  job dispatch callback.
- `temporal` uses `TemporalFlowExecutionDriver`; the host supplies the Temporal
  SDK workflow-start callback.

Choose the driver once when the flow starts. The dispatcher records it in the
state metadata as `execution_driver` and rejects a later dispatch that attempts
to move the same execution to another runtime:

```php
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;

$receipt = (new FlowExecutionDispatcher([
    $inlineDriver,
    $queueDriver,
    $temporalDriver,
]))->dispatch(new FlowExecutionRequest(
    definition: $definition,
    state: $state,
    context: ['channel_id' => 'channel-1'],
    driver: 'temporal',
    subject: $subject,
));
```

The state-store driver (`database` or `redis`) remains independent from this
execution driver. A Temporal execution can project state to PostgreSQL or
Redis, while a queued execution can use either store.

## Handle deferred completion

An executor can return `StepResult::deferred()` when an external callback will
finish the step later. Store the returned state, then apply a correlated
completion from the callback handler:

```php
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\StepResult;

$waiting = StepResult::deferred(
    message: 'Waiting for the provider callback.',
    metadata: ['request_id' => 'req-123'],
);
// Return $waiting from FlowStepExecutor::execute() and persist the runner's state.

$completion = new FlowDeferredCompletion(
    flowKey: 'channel_setup',
    stepId: 'authorize',
    idempotencyKey: 'provider-event-123',
    result: StepResult::completed('Provider callback received.'),
);

$state = (new FlowStateTransition())->complete(
    definition: $definition,
    state: $state,
    completion: $completion,
);
```

Completions are correlated by flow and step, and repeated idempotency keys are
ignored. Use `AtomicFlowStateStore::mutate()` around this transition when
callbacks can arrive concurrently.

## Persist state through an interface

The core persistence contract is intentionally small:

```php
use Webong\WorkFlow\Contracts\FlowStateStore;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;

function saveFlowState(FlowStateStore $store, FlowDefinition $definition, FlowState $state): void
{
    $store->put($definition->key, $state);
}
```

For concurrent workers and asynchronous callbacks, use an
`AtomicFlowStateStore` (or the `ForgettableFlowStateStore` returned by the
factory):

```php
use Webong\WorkFlow\Contracts\AtomicFlowStateStore;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\ValueObjects\FlowState;

/** @var AtomicFlowStateStore $store */
$state = $store->mutate('channel_setup', function (?FlowState $current) use ($definition, $completion): FlowState {
    return (new FlowStateTransition())->complete(
        definition: $definition,
        state: $current ?? new FlowState(FlowStatus::PENDING),
        completion: $completion,
    );
});
```

The in-memory store is useful for local workflows and conformance tests. The
optional Laravel adapters below provide durable database and Redis-backed
implementations.

## Host integration

The host maps its existing step definitions into `FlowDefinition`, reads its
stored metadata into `FlowState`, and provides one `FlowStepExecutor` per
domain step. `FlowRunner` returns a new evaluated state; the host persists it
and may dispatch each executor through its own queue system.

Definitions are versioned and validate duplicate ids, unknown dependencies, and
dependency cycles at construction time. Step state includes attempts and the
next retry timestamp, and a step can return a deferred result when completion
will arrive asynchronously. Executor exceptions are converted into failed step
states using the step's retry policy; the host can persist the returned state
and retry it later. `CollectingFlowEventSink` is available for tests, while
production applications provide their own event sink.

## Optional Laravel persistence

The optional provider binds `FlowStateStoreFactory` to a polymorphic Eloquent
database store or an atomic Redis store. See the [Laravel guide](docs/laravel.md)
for install commands, configuration, model identity, and the optional editable
definition models. Laravel is not required for the core PHP library.

## Optional Temporal adapter

Temporal is an optional **execution** adapter, not a state store. It requires
host-owned client and worker setup; setting an environment variable does not
make the standalone RPC module use it. See the [Temporal guide](docs/temporal.md)
for the actual boundary and prerequisites.

## Use Laravel Boost while integrating WorkFlow

The package ships optional Boost guidelines and a skill that Laravel hosts can
discover. They help inspect the host app; they do not create executors or
authorize actions. See [Laravel integration](docs/laravel.md#laravel-boost).

## Docker test environment

Use the isolated PHP 8.3, PostgreSQL, and Redis test stack:

```sh
make docker-test
make docker-down
```

See [development](docs/development.md) for focused tests and the executable
example in the container.

## Standalone JSON-RPC module

[`mod/README.md`](mod/README.md) is the end-to-end guide to the optional
Go/FrankenPHP JSON-RPC service. The PHP library itself has no HTTP server.

## TypeScript contracts

The package exposes `types/work-flow.d.ts` for consumers that want stable
TypeScript contracts immediately. When the development dependency is installed,
`composer types` regenerates `types/work-flow.generated.ts` using
`paneon/php-to-typescript`; the generated file is checked by CI.
