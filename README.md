# WorkFlow

WorkFlow is a framework- and delivery-surface-agnostic vocabulary and state
evaluator for product flows. It can coordinate connection setup, onboarding,
conversation policies, attention states, and actions across mobile apps, CLIs,
GUIs, APIs, and background processes without depending on a transport,
persistence layer, queue, or UI framework.

- Composer package: `webong/workflow`
- PHP namespace: `Webong\WorkFlow`

The core package deliberately does not execute jobs or require a persistence
backend. The host runtime supplies those adapters and stores the serialized
state wherever its domain requires. An optional Laravel integration ships in
this package for applications that want a polymorphic Eloquent database store
or an atomic Redis store.

## Core vocabulary

- `FlowDefinition`: a named flow and its step definitions.
- `FlowStepDefinition`: a step's stable identity, label, dependencies, and policy.
- `FlowState`: the evaluated state of a flow and its step states.
- `FlowEvaluator`: derives a flow state from a definition and stored data.
- `FlowPresentation`: a UI-neutral semantic message with severity, actions, and metadata.
- `FlowAction`: a UI-neutral action descriptor.
- `FlowDefinitionProvider`: resolves definitions supplied by the host domain.
- `FlowPresentationResolver`: turns state into a presentation for a surface.
- `FlowActionHandler`: lets the host runtime execute domain actions.
- `FlowContext` and `ArrayFlowContext`: provide host-owned runtime context.
- `FlowStepExecutor`: executes one domain step without coupling WorkFlow to a
  queue or framework.
- `FlowDeferredCompletionHandler`: applies an asynchronous callback to the
  matching flow and deferred step while enforcing correlation and idempotency.
- `FlowRunner`: executes supported steps in order and respects dependencies.
- `FlowStateTransition`: produces immutable running/completed/failed/reset states.
- `FlowActionDispatcher`: delegates host-owned actions through a registry.
- `FlowPresentationFactory`: provides neutral semantic presentations for flow states.
- `FlowRetryPolicy`: describes attempts, backoff, and idempotency.
- `FlowEvent`: serializable lifecycle event descriptor for host event buses.
- `FlowStateMigrationRunner`: upgrades persisted state between schema versions.
- `FlowActionAuthorizer`: lets the host enforce domain permissions before an
  action is dispatched.
- `FlowEventSink`: receives lifecycle events without coupling the package to an
  event bus; the host can persist or publish them.
- `FlowStateSerializer`: defines the durable array representation used by
  stores.
- `FlowStateSubject`: identifies the polymorphic subject that owns a flow state
  without exposing a framework model to the core.
- `AtomicFlowStateStore`: adds a locked read-modify-write mutation operation
  for asynchronous callbacks and concurrent workers.
- `ForgettableFlowStateStore`: adds explicit flow-state deletion for lifecycle
  cleanup without forcing every store implementation to support it.
- `FlowStateStoreFactory`: creates a subject-bound, atomic, forgettable store
  through an interface.
- `FlowActionContext`: carries actor, resource, and host-owned authorization
  attributes into action handlers.
- `FlowExecutionDriver`: dispatches a flow to an execution runtime by name.
- `FlowExecutionDispatcher`: selects a registered driver and records the
  selected runtime in the flow snapshot.
- `FlowExecutionRequest` and `FlowExecutionReceipt`: serializable dispatch
  input and outcome value objects.

The same vocabulary can represent a failed webhook setup step or a
conversation banner that asks an operator to use a template. Domain code owns
the meaning and execution of those actions.

## Installation

Install the core package in any PHP application, command-line tool, worker, or
API:

```sh
composer require webong/workflow
```

The core has no Laravel, queue, HTTP, database, or Redis runtime dependency.

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

Install the optional Laravel components in the host application:

```sh
composer require illuminate/cache illuminate/database spatie/eloquent-sortable
php artisan vendor:publish --tag=work-flow-config
php artisan vendor:publish --tag=work-flow-migrations
php artisan migrate
```

The provider and Eloquent/Redis adapters live under the optional `ext/Laravel`
integration and are registered through Laravel package discovery. Set
`WORK_FLOW_STORE=database` (the default) for Eloquent or
`WORK_FLOW_STORE=redis` for Redis. The database connection can be selected with
`WORK_FLOW_DATABASE_CONNECTION`; Redis uses `WORK_FLOW_REDIS_STORE` and
`WORK_FLOW_REDIS_PREFIX`. Redis expiration is disabled by default because
workflow state is authoritative; configure the published `ttl` only when that
lifecycle is intentional.

Add `HasWorkflowStates` to any Eloquent model that owns flow state. The subject
type should use the model's resolved morph class, so custom morph maps remain
compatible. Inject `FlowStateStoreFactory` into your application service; the
provider returns a subject-bound store for the configured database or Redis
driver:

```php
use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;
use Webong\WorkFlow\ValueObjects\FlowState;

/** @var FlowStateStoreFactory $factory */
$store = $factory->for(new FlowStateSubject(
    type: $model->getMorphClass(),
    id: (string) $model->getKey(),
));

$store->mutate('setup', static function (?FlowState $state): FlowState {
    return $state ?? new FlowState(FlowStatus::PENDING);
});
```

For persisted, editable definitions, `WorkflowDefinitionRecord` owns ordered
`WorkflowStepDefinitionRecord` rows. The step model uses
the optional `WorkflowSortable` trait on top of `spatie/eloquent-sortable`,
scopes reordering to its parent definition, and
converts the ordered records back to the framework-neutral `FlowDefinition`:

```php
$definition = WorkflowDefinitionRecord::with('steps')->findOrFail($id);
$flow = $definition->toFlowDefinition();

$definition->steps()->ordered()->get();
$definition->steps()->findOrFail($stepId)->moveToStart();

WorkflowStepDefinitionRecord::setNewOrderForDefinition(
    $definition->getKey(),
    [$firstStepId, $secondStepId],
);
```

Creation and instance move operations are scoped to the parent definition. Use
`setNewOrderForDefinition()` for bulk reordering so another definition cannot
be changed accidentally. These scoped operations and `toFlowDefinition()`
throw when a step would appear before one of its dependencies.

The sortable dependency is only needed for this optional Eloquent definition
adapter; the core evaluator and state stores do not depend on it.

## Optional Temporal adapter

The repository also contains an optional Temporal adapter under `ext/Temporal`.
It keeps the core package independent from Temporal while providing a thin
mapping for hosts that need durable, long-running execution:

- `TemporalFlowWorkflow` runs the deterministic orchestration loop.
- `TemporalFlowActivity` delegates one step to the host's existing
  `FlowStepExecutor` instances.
- `TemporalFlowCompletion` serializes deferred provider callbacks for a
  Temporal Signal.
- `TemporalFlowIdentity` creates a stable subject-and-flow Workflow ID.
- `TemporalFlowInput` serializes a definition, initial state, and context.

Install the SDK in the host application, not in WorkFlow's core runtime:

```sh
composer require temporal/sdk
```

The Temporal PHP SDK requires the `grpc` extension for clients and RoadRunner
for workers. Register `TemporalFlowActivity` with the worker using the host's
executors, then start `TemporalFlowWorkflow` with the arrays returned by
`TemporalFlowInput::toArray()`. Signal deferred callbacks with
`TemporalFlowCompletion::toArray()` and the workflow's `complete` signal.
The adapter disables Temporal's default Activity retry loop so each execution
is reported once and the WorkFlow step's `FlowRetryPolicy` controls retries.

Laravel applications can publish `config/work-flow.php` and configure the
execution default and Temporal connection/worker defaults through the optional
`execution` and `temporal` sections:

```dotenv
WORK_FLOW_EXECUTION_DRIVER=inline
WORK_FLOW_TEMPORAL_ENABLED=true
WORK_FLOW_TEMPORAL_ADDRESS=127.0.0.1:7233
WORK_FLOW_TEMPORAL_NAMESPACE=default
WORK_FLOW_TEMPORAL_TASK_QUEUE=work-flow
```

The application owns driver registration and the Temporal client/worker
bootstrap; the package does not bind either one because `temporal/sdk` is
optional. Read `config('work-flow.execution.default')` when selecting a default
driver and `config('work-flow.temporal')` when constructing the host's Temporal
client and worker. Register `TemporalFlowWorkflow` plus `TemporalFlowActivity`
on the same task queue.

Temporal's event history is authoritative for this adapter. Do not mutate the
Laravel database or Redis WorkFlow stores from inside a Temporal Workflow;
those stores may be used as projections for API and UI queries. Keep HTTP,
database, Redis, and other side effects inside Activities.

The regular PHP 8.3 test stack validates the SDK-neutral Temporal payload and
identity helpers. Add the Temporal SDK, a Temporal development service, and a
RoadRunner worker to run engine-level integration tests.

## Use Laravel Boost while integrating WorkFlow

Laravel Boost is an optional development assistant for the Laravel application
that consumes WorkFlow. It is not a runtime dependency of this package and it
does not replace the WorkFlow contracts or adapters. This package ships a
Boost guideline at `resources/boost/guidelines/core.blade.php` and an on-demand
skill at `resources/boost/skills/work-flow-development/SKILL.md`. After adding
WorkFlow to a Laravel application, install Boost if it is not already present:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Then run `php artisan boost:update --discover` when you want Boost to scan for
newly installed package resources. Boost will discover and install WorkFlow's
guideline and skill according to the user's selected features.
Use the resulting guidance to inspect the host application and verify the
integration in the same environment where the app runs:

This follows Laravel Boost's [third-party package AI guidelines](https://laravel.com/framework/docs/boost#third-party-package-ai-guidelines).

- Use Boost's `search-docs` before changing the service provider, migrations,
  cache configuration, or test setup so examples match the installed Laravel
  version.
- Use `database-schema` before adding application-specific relationships around
  `workflow_states`; the polymorphic owner columns must remain paired with the
  flow key and unique constraint supplied by the published migration.
- Use `database-query` when diagnosing a missing, stale, or unexpectedly reset
  flow state, and use `browser-logs` when a UI action surfaces an integration
  error.
- Run the published migration, configuration, and focused adapter tests through
  the host application's Artisan/Sail workflow rather than resolving Laravel
  services from WorkFlow's framework-neutral core.
- Inspect application logs and browser errors when a UI action dispatches a
  `FlowAction`; WorkFlow supplies the action descriptor, while the Laravel app
  owns authorization, routing, and side effects.

Useful Boost requests during setup include:

```text
Show the installed Laravel documentation for registering a package service provider,
then verify that WorkFlow's provider and published migration are loaded.

Inspect the workflow_states schema and confirm the polymorphic subject columns,
flow key, unique constraint, and indexes support the configured WorkFlow store.

Run the focused WorkFlow Laravel adapter tests using the application's configured
PostgreSQL and Redis services, and explain any lock or migration failure.
```

Keep the boundary explicit: Boost may help inspect, configure, and test a
Laravel host, but the host still injects `FlowStateStoreFactory`, supplies its
own queue and HTTP integrations, and persists state through the package's
interfaces.

For adapter development, the default test suite uses SQLite and an in-memory
lock provider. Production-driver coverage is available when services are
configured:

```sh
WORK_FLOW_POSTGRES_DSN='pgsql:host=127.0.0.1;port=5432;dbname=workflow_test' \
WORK_FLOW_POSTGRES_USER=workflow \
WORK_FLOW_POSTGRES_PASSWORD=workflow \
WORK_FLOW_REDIS_HOST=127.0.0.1 \
WORK_FLOW_REDIS_PORT=6379 \
vendor/bin/phpunit tests/Laravel/ProductionFlowStateStoreTest.php
```

The production-driver tests skip only when their corresponding environment
variables are absent; CI provisions PostgreSQL and Redis services for them.

## Docker test environment

The repository includes an isolated PHP 8.3, PostgreSQL, and Redis test stack.
It does not share containers or volumes with the CRM application:

```sh
make docker-test
make docker-down
```

The container resolves development dependencies with PHP 8.3 on each test run,
so validation does not depend on the host PHP version or its Composer lockfile.
The Compose project uses `workflow_*` named volumes and maps PostgreSQL to
port `55432` and Redis to `56379` by default. Override those host ports with
`WORK_FLOW_POSTGRES_PORT` and `WORK_FLOW_REDIS_PORT_HOST` if needed.

When an external callback completes a deferred step, the host passes a
`FlowDefinition`, the stored `FlowState`, and a `FlowDeferredCompletion` to a
`FlowDeferredCompletionHandler`. The handler rejects mismatched flows, unknown
or non-deferred steps, applies the callback's completed/failed/pending result,
re-evaluates the flow, and ignores duplicate idempotency keys. Pending callback
results remain deferred so a later callback can complete the same step.

## Standalone JSON-RPC module

[`mod/README.md`](mod/README.md) documents the optional standalone Go server
that embeds FrankenPHP and serves this package over authenticated JSON-RPC.
It uses PHP 8.3 and a framework-neutral PDO PostgreSQL state store. The
Laravel and Temporal adapters remain under `ext/`; using the module does not
add either framework to the core package.

## TypeScript contracts

The package exposes `types/work-flow.d.ts` for consumers that want stable
TypeScript contracts immediately. When the development dependency is installed,
`composer types` regenerates `types/work-flow.generated.ts` using
`paneon/php-to-typescript`; the generated file is checked by CI.
