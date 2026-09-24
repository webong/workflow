# WorkFlow

WorkFlow helps your application run and track a process that takes several
steps: authorize a provider, wait for its webhook, then verify a connection.
Your API, CLI, or UI can read the same progress and show what needs attention.

Install it in any PHP 8.3+ application:

```sh
composer require webong/workflow
```

Your application supplies the business operations and decides when to resume
them. The core has no Laravel, database, queue, HTTP, or UI dependency.

## See a complete flow work

The [channel setup example](examples/channel-setup.php) includes starting a
run, reading saved state, waiting for a webhook, and resuming. It uses simulated
provider responses and an in-memory store.

From this repository, use the PHP 8.3 container:

```sh
docker compose -f docker-compose.test.yml up -d php postgres redis
docker compose -f docker-compose.test.yml exec -T php composer install
docker compose -f docker-compose.test.yml exec -T php php examples/channel-setup.php
```

Expected output:

```text
Started channel-42-setup-1: running; webhook: pending
Resume while waiting: webhook attempts = 1
Webhook received: running
After resume: completed; verify: completed
```

Add `--fail-verification` to the last command to see a failure that needs an
operator to correct the provider settings. For a shorter example, run
[order approval](examples/order-approval.php).

## How it fits together

1. Define the process with `FlowDefinition` and `FlowStepDefinition`.
2. Create a `FlowRun` with a unique ID. It saves the definition used by this
   occurrence, so publishing a later version cannot change an active run.
3. Implement `FlowStepExecutor` for your operations. Return `completed()`,
   `failed()`, `skipped()`, or `deferred()`.
4. Run eligible steps with `FlowRunner` and save the returned `FlowState`.
5. Apply a callback with its run ID, step ID, attempt, and event ID. Then
   explicitly resume to execute the next eligible steps.

A deferred step stays waiting when you resume. Completed steps are not
repeated. Starting the process again uses a new run ID.

## Choose your integration

| Your application needs | Guide |
| --- | --- |
| Flows inside a PHP application or CLI | [PHP library: start, save, complete, resume](docs/php-library.md) |
| Laravel models with database or Redis state | [Laravel installation and example](docs/laravel.md) |
| Calls from another language or backend | [Go/FrankenPHP JSON-RPC service](mod/README.md) |
| Temporal execution and durable timers | [Optional Temporal adapter](docs/temporal.md) |
| Retry, cancellation, concurrency, and upgrade rules | [Run lifecycle](docs/run-lifecycle.md) |
| Package development and testing | [Development guide](docs/development.md) |

Laravel and Temporal stay optional under `ext/`. The standalone service lives
under `mod/` and accepts calls from trusted backends. Client-facing apps
authenticate their users and authorize the subject before invoking it.

## Choose execution and storage separately

`FlowExecutionDriver` chooses where the **run** executes: inline, a host queue
job, or Temporal. `FlowStateStore` chooses where ordinary run state is saved.
Use `RunScopedFlowStateStore` around a subject-bound store to keep repeated
runs separate; it works with the included database, Redis, PDO, and memory
stores.

Temporal owns its runs through its event history. A database or Redis copy of
a Temporal run is a read projection. Installing the adapter does not start
a Temporal server or worker.

WorkFlow core does not schedule retries, deliver an outbox, or guarantee
exactly-once external effects. Your host owns those guarantees. See the
[lifecycle guide](docs/run-lifecycle.md) before adding concurrent workers.

## Actions and errors

`FlowAction` and `FlowPresentation` describe what a host may show.
`FlowActionDispatcher` requires a host `FlowActionAuthorizer` before invoking
a handler. A visible action is not permission to execute it.

Unexpected executor exceptions become a safe failure message with an error
code and correlation ID. Supply `FlowFailureReporter` to send the original
exception to private logs. Explicit messages returned by your executors must
also be safe to display.

## Laravel Boost and TypeScript

The package includes [Laravel Boost guidelines and a skill](docs/laravel.md#laravel-boost)
to help apps integrate the run, callback, and persistence contracts.

[types/work-flow.d.ts](types/work-flow.d.ts) describes the serialized API.
`composer types` regenerates the PHP-derived contracts used by CI.

## Test the package

```sh
make docker-test
make docker-down
```

The test stack uses PHP 8.3, PostgreSQL, and Redis. See
[upgrade notes](docs/run-lifecycle.md#upgrading-existing-integrations) for the
run-aware RPC and authorization changes.
