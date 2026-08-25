---
name: work-flow-development
description: Build and integrate WorkFlow definitions, executors, state transitions, deferred callbacks, and optional Laravel persistence.
---

# WorkFlow Development

## When to use this skill

Use this skill when adding or changing WorkFlow flows, step executors, state
transitions, deferred provider callbacks, or the optional Laravel database and
Redis adapters.

## Architecture

WorkFlow is framework-neutral. Keep the core contracts and value objects free of
Laravel, Eloquent, Redis, HTTP, queues, and UI concerns. The host runtime owns
those integrations and injects them at the boundary.

Important contracts and services:

- `FlowDefinition` contains a versioned flow and ordered `FlowStepDefinition`
  instances.
- `FlowEvaluator` derives a `FlowState` from a definition and stored state.
- `FlowRunner` executes host-provided `FlowStepExecutor` instances and returns
  a new state.
- `FlowStateStore` reads and writes state; `AtomicFlowStateStore::mutate()`
  protects concurrent read-modify-write transitions.
- `FlowStateTransition` creates running, completed, failed, reset, and deferred
  completion states.
- `FlowStateStoreFactory` returns a subject-bound
  `ForgettableFlowStateStore`.

## Define and execute steps

Use `FlowStepDefinition`, never the old `StepDefinition` name. Dependencies must
reference existing step IDs, and definitions must not contain cycles.

<code-snippet name="Flow step executor" lang="php">
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
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
        return StepResult::completed('Channel authorized.');
    }
};
</code-snippet>

Executors may return `completed`, `failed`, `skipped`, `pending`, or `deferred`
results. The host decides whether to run them synchronously or dispatch them to
a queue.

## Deferred callbacks and concurrency

Return `StepResult::deferred()` for operations completed by a webhook or other
external callback. Construct a `FlowDeferredCompletion` with the flow key, step
ID, stable idempotency key, and callback result. Apply it through
`FlowStateTransition::complete()` and persist the result inside
`AtomicFlowStateStore::mutate()`.

Never perform a callback transition as an unlocked `get()` followed by `put()`;
that can lose a concurrent update. The database adapter uses a transaction and
`lockForUpdate()`. The Redis adapter locks the complete deserialize,
transition, and serialize/write cycle.

## Laravel adapter

The Laravel integration is optional, lives under `ext/Laravel`, and is
registered by package discovery.
Inject `FlowStateStoreFactory` rather than resolving a concrete adapter. Build a
neutral subject from an Eloquent model only at the Laravel boundary:

<code-snippet name="Create a subject-bound Laravel store" lang="php">
use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;

$store = $factory->for(new FlowStateSubject(
    type: $model->getMorphClass(),
    id: (string) $model->getKey(),
));
</code-snippet>

Publish the config and migrations before using the Eloquent store. The
polymorphic subject columns and flow key are jointly unique. Use the Redis
adapter only with a lock-capable cache store, and leave TTL unset unless state
expiration is explicitly part of the application's lifecycle.

## Temporal adapter

The optional Temporal integration lives under `ext/Temporal` and is loaded only
when the host installs `temporal/sdk`. Keep Temporal Workflow code deterministic:
`TemporalFlowWorkflow` may evaluate definitions and state, await timers, and
receive Signals, but HTTP, database, Redis, and other side effects must run in
`TemporalFlowActivity` through host-provided `FlowStepExecutor` instances.

Use `TemporalFlowIdentity` for stable subject/flow Workflow IDs,
`TemporalFlowInput` for serializable workflow arguments, and
`TemporalFlowCompletion` for deferred callback Signal payloads. Temporal event
history is the source of truth; do not use the Laravel state stores for
read-modify-write inside a Temporal Workflow. Project state to those stores only
from Activities or application listeners. The adapter disables Temporal's
default Activity retry loop; let `FlowRetryPolicy` own step retries. In Laravel,
read `config('work-flow.temporal')` for the address, namespace, task queue, and
feature flag, while the host application owns SDK client and worker bootstrap.

## Validation

Run all package validation in the PHP 8.3 container:

<code-snippet name="Validate WorkFlow" lang="shell">
make docker-test
</code-snippet>

This runs PHPUnit, PostgreSQL and Redis integration coverage, PHPStan, and
TypeScript contract generation. Preserve the package's framework-agnostic
boundary when adding Laravel conveniences.
