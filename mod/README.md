# Use WorkFlow over JSON-RPC

Use this module when a **trusted backend** needs to start and inspect flows
over HTTP instead of embedding the PHP library. A Go server embeds FrankenPHP
and exposes JSON-RPC 2.0; PHP owns definitions, execution, and transitions;
PostgreSQL stores the state. This is a separate deployment under `mod/`, not
an `ext/` adapter.

The single `workflowd` process serves `/rpc` and `/healthz`. Its Docker image
includes the PHP 8.3 runtime and package code; the binary is not a standalone
static executable and needs the compatible FrankenPHP/PHP shared libraries.

## Run the example locally

You need Docker with Compose. From the repository root, start the example
service and PostgreSQL:

```sh
docker compose -f mod/compose.yml up -d --build
curl -i http://127.0.0.1:18080/healthz
```

The health request should return `204 No Content`. The example defines one
flow, `demo_approval`, with an `approve` step that waits for a callback.

Start it for an order:

```sh
curl -sS http://127.0.0.1:18080/rpc \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer local-development-token' \
  -d '{"jsonrpc":"2.0","id":1,"method":"flow.start","params":{"flow_key":"demo_approval","subject":{"type":"order","id":"demo-1"}}}'
```

Look for `"status":"running"` and an `approve` step with
`"status":"pending"`. The identity of this state is the combination of
`subject.type`, `subject.id`, and `flow_key`.

Read it again (even in a later process or request):

```sh
curl -sS http://127.0.0.1:18080/rpc \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer local-development-token' \
  -d '{"jsonrpc":"2.0","id":2,"method":"flow.get","params":{"flow_key":"demo_approval","subject":{"type":"order","id":"demo-1"}}}'
```

Simulate an approval event and finish the waiting step:

```sh
curl -sS http://127.0.0.1:18080/rpc \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer local-development-token' \
  -d '{"jsonrpc":"2.0","id":3,"method":"flow.complete","params":{"flow_key":"demo_approval","subject":{"type":"order","id":"demo-1"},"step_id":"approve","idempotency_key":"approval-1","result":{"status":"completed","message":"Approved"}}}'
```

Look for `"status":"completed"`. Calling `flow.complete` again with the
same `idempotency_key` returns the same completed state. Calling `flow.start`
again for the same subject and flow returns its existing state rather than
running it from the beginning.

Stop the example with `docker compose -f mod/compose.yml down`. The named
PostgreSQL volume remains, so `demo-1` still has its state on the next start.
Use a different subject ID when trying the sequence again.

## What can a caller do?

All calls are `POST /rpc` with `Content-Type: application/json`, a
`Bearer` token, `jsonrpc: "2.0"`, and an `id`. The `params` object varies:

| Method | Required params | Result |
| --- | --- | --- |
| `flow.definition` | `flow_key` | The host-defined flow definition |
| `flow.start` | `flow_key`, `subject` | Existing or newly started state; optional `context` object |
| `flow.get` | `flow_key`, `subject` | Current state, or `null` before start |
| `flow.complete` | `flow_key`, `subject`, `step_id`, `idempotency_key`, `result` | State after a deferred step completes |

`subject` is an object with string `type` and `id` fields. `result.status`
may be `completed`, `failed`, or `skipped`, with optional `message` and
`error`. `flow.start` currently accepts only the `inline` execution driver;
the API does not expose queue or Temporal execution yet. JSON-RPC batches and
notifications are accepted, but use request IDs for operations whose result
you need to inspect. Internal failures are logged and returned as generic
JSON-RPC errors.

## Replace the demo with your flows

The Compose file points `WORKFLOW_BOOTSTRAP` at
[`bootstrap.example.php`](php/bootstrap.example.php), which defines the demo
flow and executor. The Compose PostgreSQL service applies
[`postgres.sql`](php/schema/postgres.sql) when it first creates its data
volume.

For a real deployment, provide a trusted PHP bootstrap that returns a
`FlowRpcApplication`. It must register your `FlowDefinitionProvider`,
`FlowStepExecutor` implementations, `FlowExecutionDispatcher`, and
`FlowStateStoreFactory`. The example uses the included PDO PostgreSQL store;
you can supply another implementation of the same store interface. Build a
derived image or mount trusted application code and set `WORKFLOW_BOOTSTRAP`
to its absolute path. Never accept PHP code or flow definitions from RPC
callers. `WORKFLOW_PHP_PUBLIC` selects the bundled PHP bridge document root.

`flow.start` currently supports the `inline` execution driver only. The
Laravel queue and Temporal execution adapters remain separate; this module
does not silently switch runtimes. Since the inline executor runs under the
PostgreSQL state lock, keep it short and avoid irreversible external side
effects inside it. Long-running or externally side-effecting steps should
defer and complete through `flow.complete`; a future queue/Temporal module
can take ownership of those execution semantics.

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
- PostgreSQL connection settings in the example are `WORKFLOW_POSTGRES_DSN`,
  `WORKFLOW_POSTGRES_USER`, and `WORKFLOW_POSTGRES_PASSWORD`. Apply the schema
  before starting against an existing database. Back up the state table as
  part of your normal database backup.
- Requests are limited to 1 MiB. Keep the RPC endpoint on a private network
  and apply ingress rate limits appropriate to the host.

The module image build runs the Go RPC tests and vet, then compiles the
PHP-linked binary in the PHP 8.3 FrankenPHP builder; the host may lack its
native PHP headers. Run the PHP suite in the repository's PHP 8.3 test
container with `make docker-test`.

If a call returns `401`, check the bearer token. A JSON-RPC `-32601` means
the method is unknown; `-32602` means parameters are invalid. An "Internal
error" response hides details from the caller; inspect `workflowd` logs with
`docker compose -f mod/compose.yml logs workflowd`.
