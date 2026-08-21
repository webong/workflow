# WorkFlow

WorkFlow is a framework- and delivery-surface-agnostic vocabulary and state
evaluator for product flows. It can coordinate connection setup, onboarding,
conversation policies, attention states, and actions across mobile apps, CLIs,
GUIs, APIs, and background processes without depending on a transport,
persistence layer, queue, or UI framework.

- Composer package: `webong/work-flow`
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

The same vocabulary can represent a failed webhook setup step or a
conversation banner that asks an operator to use a template. Domain code owns
the meaning and execution of those actions.

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

The provider is registered through Laravel package discovery. Set
`WORK_FLOW_STORE=database` (the default) for Eloquent or
`WORK_FLOW_STORE=redis` for Redis. The database connection can be selected with
`WORK_FLOW_DATABASE_CONNECTION`; Redis uses `WORK_FLOW_REDIS_STORE` and
`WORK_FLOW_REDIS_PREFIX`. Redis expiration is disabled by default because
workflow state is authoritative; configure the published `ttl` only when that
lifecycle is intentional.

Add `HasWorkflowStates` to any Eloquent model that owns flow state. The subject
type should use the model's resolved morph class, so custom morph maps remain
compatible:

```php
use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;
use Webong\WorkFlow\ValueObjects\FlowState;

$store = $factory->for(new FlowStateSubject(
    type: $model->getMorphClass(),
    id: (string) $model->getKey(),
));

$store->mutate('setup', static function (?FlowState $state): FlowState {
    // Return the next immutable state.
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

## TypeScript contracts

The package exposes `types/work-flow.d.ts` for consumers that want stable
TypeScript contracts immediately. When the development dependency is installed,
`composer types` regenerates `types/work-flow.generated.ts` using
`paneon/php-to-typescript`; the generated file is checked by CI.
