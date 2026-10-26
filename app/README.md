# Use WorkFlow over JSON-RPC

Use this module when a **trusted backend** needs to start and inspect flows
over HTTP instead of embedding the PHP library. A Go server embeds FrankenPHP
and exposes JSON-RPC 2.0; PHP owns definitions, execution, and transitions.
The included bootstrap can store state in PostgreSQL, MySQL, or SQLite. This
is a separate deployment under `app/`, not an `ext/` adapter.

The single `workflowd` process serves `/rpc` and `/healthz`. Its Docker image
includes the PHP 8.3 runtime and package code; the binary is not a standalone
static executable and needs the compatible FrankenPHP/PHP shared libraries.
CI builds and smoke-tests the image on Linux AMD64 and ARM64; version tags
publish it to GHCR. See [distributions and releases](../docs/releases.md).

## Run the example locally

You need Docker with Compose. From the repository root, start the example
service and its default PostgreSQL database:

```sh
docker compose -f app/compose.yml up -d --build
curl -i http://127.0.0.1:18080/healthz
```

The health request should return `204 No Content`. The example defines one
flow, `demo_approval`, with an `approve` step that waits for a callback.

Start it for an order:

```sh
curl -sS http://127.0.0.1:18080/rpc \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer local-development-token' \
  -d '{"jsonrpc":"2.0","id":1,"method":"flow.start","params":{"flow_key":"demo_approval","run_id":"approval-1","subject":{"type":"order","id":"demo-1"}}}'
```

Look for `"status":"running"` and an `approve` step with
`"status":"pending"`. The identity of this state is the combination of
`subject.type`, `subject.id`, `flow_key`, and `run_id`.

Read it again (even in a later process or request):

```sh
curl -sS http://127.0.0.1:18080/rpc \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer local-development-token' \
  -d '{"jsonrpc":"2.0","id":2,"method":"flow.get","params":{"flow_key":"demo_approval","run_id":"approval-1","subject":{"type":"order","id":"demo-1"}}}'
```

Simulate an approval event and finish the waiting step:

```sh
curl -sS http://127.0.0.1:18080/rpc \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer local-development-token' \
  -d '{"jsonrpc":"2.0","id":3,"method":"flow.complete","params":{"flow_key":"demo_approval","run_id":"approval-1","subject":{"type":"order","id":"demo-1"},"step_id":"approve","attempt":1,"idempotency_key":"approval-event-1","result":{"status":"completed","message":"Approved"}}}'
```

Look for `"status":"completed"`. Calling `flow.complete` again with the
same `idempotency_key` returns the same completed state. Calling `flow.start`
again for the same subject, flow, and run ID returns its existing state. A new
run ID starts another occurrence using the latest host definition.

For a flow with dependent steps, completing a callback only records its
result. Resume explicitly to run the next steps:

```sh
curl -sS http://127.0.0.1:18080/rpc \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer local-development-token' \
  -d '{"jsonrpc":"2.0","id":4,"method":"flow.resume","params":{"flow_key":"demo_approval","run_id":"approval-1","subject":{"type":"order","id":"demo-1"}}}'
```

Resume uses the definition and initial context saved with the run. A completed
run simply returns its existing state.

Stop the example with `docker compose -f app/compose.yml down`. The named
PostgreSQL volume remains, so `demo-1` still has its state on the next start.
Use a different run ID when trying the sequence again.

## What can a caller do?

All calls are `POST /rpc` with `Content-Type: application/json`, a
`Bearer` token, `jsonrpc: "2.0"`, and an `id`. The `params` object varies:

Successful lifecycle responses put the snapshot in `result.state`; a missing
run returns `result.state: null`. `flow.definition` uses `result.definition`.
Check for a top-level JSON-RPC `error` before reading either field.

| Method | Required params | Result |
| --- | --- | --- |
| `flow.definition` | `flow_key` | The host-defined flow definition |
| `flow.start` | `flow_key`, `subject`, `run_id` | Existing or newly started run; optional `context` object and registered `driver` name |
| `flow.get` | `flow_key`, `subject`, `run_id` | Current state, or `null` before start |
| `flow.complete` | `flow_key`, `subject`, `run_id`, `step_id`, `attempt`, `idempotency_key`, `result` | State after a deferred step completes |
| `flow.resume` | `flow_key`, `subject`, `run_id` | State after one eligible execution pass |
| `flow.cancel` | `flow_key`, `subject`, `run_id` | Cancelled state; future callbacks and work stop |
| `flow.validate` | `definition` | Structured authoring diagnostics without registering or executing the definition |
| `flow.operations` | `flow_key` | Host-published operation descriptors for the registered definition |

`subject` is an object with string `type` and `id` fields. `result.status`
may be `completed`, `failed`, or `skipped`, with optional `message` and
`error`, a boolean `retriable`, and a `metadata` object. An explicit
`retriable: false` prevents retry even when the step has a retry policy.
`flow.start` defaults to `inline` and accepts only driver names registered by
the trusted PHP bootstrap. JSON-RPC batches are
accepted. Mutation notifications without an `id` do not execute; read
notifications return no response. Internal failures return generic JSON-RPC
errors. Initial context is saved for resume and omitted from responses.

## Replace the demo with your flows

The Compose file points `WORKFLOW_BOOTSTRAP` at
[`bootstrap.example.php`](bootstrap.example.php), which defines the demo
flow and executor. The Compose PostgreSQL service applies
[`postgres.sql`](schema/postgres.sql) when it first creates its data
volume.

For a real deployment, provide a trusted PHP bootstrap that returns a
`FlowRpcApplication`. It must register your `FlowDefinitionProvider`,
`FlowStepExecutor` implementations, `FlowExecutionDispatcher`, and
`FlowStateStoreFactory`. The example ships three interchangeable PDO stores —
PostgreSQL, MySQL, and SQLite — selected with `WORKFLOW_DB_DRIVER`; you can
supply another implementation of the same store interface. Build a
derived image or mount trusted application code and set `WORKFLOW_BOOTSTRAP`
to its absolute path. Never accept PHP code or client definitions for
registration or execution; `flow.validate` may inspect an unregistered
definition but cannot run or save it. `WORKFLOW_PHP_PUBLIC` selects the
bundled PHP bridge document root.

For example, a standalone host can register its queue publisher without a
Laravel dependency:

```php
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\Services\InlineFlowExecutionDriver;
use Webong\WorkFlow\Services\QueuedFlowExecutionDriver;

$execution = new FlowExecutionDispatcher([
    new InlineFlowExecutionDriver($runner, $executors),
    new QueuedFlowExecutionDriver(
        dispatch: fn ($request): string => $queue->publish($request->toArray()),
    ),
    // new TemporalFlowExecutionDriver($startTemporalWorkflow),
]);
```

The queue callback returns its stable job/execution ID. The consumer loads the
saved run, performs the claimed work, and persists through the same store.

Register `QueuedFlowExecutionDriver` or `TemporalFlowExecutionDriver` in the
bootstrap's `FlowExecutionDispatcher` to expose `queue` or `temporal`.
Merely installing an adapter or setting environment variables enables
nothing. The RPC service persists the selected driver before dispatch,
commits the transaction, dispatches outside the store lock, and saves the
result or receipt under that claim. An async receipt never overwrites state a
fast worker already advanced.

Repeating `flow.start` for the same run never dispatches twice. Explicit
`flow.resume` dispatches the run again with its stored driver and execution
ID; async drivers must make that operation idempotent. Queue workers use the
ordinary store as authority. Temporal history remains authoritative, so
deferred completions and cancellation must reach the Temporal signal/client,
not `flow.complete` on a stale database projection.

On first dispatch, RPC derives a deterministic opaque execution ID from the
subject type/id, flow key, and logical run ID. This prevents equal `run_id`
values in different subjects or flows from colliding in Temporal or a queue.
The driver's returned execution ID is then persisted and reused on resume.
Keep inline work short; return `deferred()` for an external wait and complete
it through `flow.complete`. External operations still need their own stable
idempotency keys.

If a process stops or result persistence fails, `flow.get` exposes a non-null
`execution_claim`. Other mutations receive `-32009`. The host must reconcile
the provider's outcome before clearing the claim or replacing the run; there
is no automatic lease expiry or blind replay. See [run lifecycle](../docs/run-lifecycle.md).

## Security and operations

- Set a unique, secret `WORKFLOW_RPC_TOKEN` and use TLS at a trusted reverse
  proxy. The Compose token is for local development only, and its port is
  bound to localhost.
- The bearer token grants access to every configured flow and subject. Do not
  expose this endpoint directly to untrusted clients. Put per-tenant and
  per-subject authorization in the trusted caller/gateway or your bootstrap
  before allowing broader access.
- Set `WORKFLOW_RPC_LISTEN` to change the address; it defaults to
  `127.0.0.1:8080` outside Compose. `/healthz` has no authentication and
  exposes no flow data.
  It checks process liveness, not PHP execution or database readiness.
- `WORKFLOW_CAPABILITIES` is an optional comma-separated grant list used by
  `flow.validate` in the example bootstrap. It is trusted host configuration;
  RPC callers cannot override it. Leave it unset to return
  `capabilities_unchecked` when definitions require capabilities.
- The Docker health check probes `/healthz` on port 8080. Set
  `WORKFLOW_HEALTH_URL` as well if you change the internal listen address.
- Database connection settings in the example are selected with
  `WORKFLOW_DB_DRIVER`: `postgres` (default), `mysql`, or `sqlite`.

  - `postgres`: `WORKFLOW_POSTGRES_DSN`, `WORKFLOW_POSTGRES_USER`, and
    `WORKFLOW_POSTGRES_PASSWORD`; apply [`schema/postgres.sql`](schema/postgres.sql).
  - `mysql`: `WORKFLOW_MYSQL_DSN`, `WORKFLOW_MYSQL_USER`, and
    `WORKFLOW_MYSQL_PASSWORD`; apply [`schema/mysql.sql`](schema/mysql.sql)
    against MySQL 8 (InnoDB).
  - `sqlite`: `WORKFLOW_SQLITE_PATH` pointing at the database file; apply
    [`schema/sqlite.sql`](schema/sqlite.sql). The bootstrap enables WAL mode
    and a 5 s busy timeout; SQLite serializes writers at the file level, so
    it suits single-host, low-contention deployments.

  Apply the schema before starting against an existing database. Back up the
  state table as part of your normal database backup.
- Requests are limited to 1 MiB. Keep the RPC endpoint on a private network
  and apply ingress rate limits appropriate to the host.

The application image build runs the Go RPC tests and vet, then compiles the
PHP-linked binary in the PHP 8.3 FrankenPHP builder; the host may lack its
native PHP headers. Run the PHP suite in the repository's PHP 8.3 test
container with `make docker-test`.
The Docker build copies `composer.json` and resolves its dependencies inside
the image; it does not require the ignored root `composer.lock` to exist in
a fresh checkout. The image installs ZIP support so Composer can install
dist archives, plus the PostgreSQL, MySQL, and SQLite PDO drivers used by the
included stores.

If a call returns `401`, check the bearer token. A JSON-RPC `-32601` means
the method is unknown; `-32602` means parameters are invalid. An "Internal
error" response hides details from the caller; inspect `workflowd` logs with
`docker compose -f app/compose.yml logs workflowd`.
