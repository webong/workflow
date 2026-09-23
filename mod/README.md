# Standalone WorkFlow module

`mod/` is a standalone deployment of the framework-neutral WorkFlow package,
not a Laravel or Temporal extension. A Go HTTP server embeds FrankenPHP and
exposes a JSON-RPC 2.0 endpoint. PHP still owns flow definitions, execution,
and state transitions. The included PostgreSQL store uses PDO, not Illuminate.

The single `workflowd` process serves `/rpc` and `/healthz`. Its Docker image
includes the PHP 8.3 runtime and package code; the binary is not a standalone
static executable and needs the compatible FrankenPHP/PHP shared libraries.

## Try the example

From the repository root:

```sh
docker compose -f mod/compose.yml up -d --build
curl -sS http://127.0.0.1:18080/healthz
curl -sS http://127.0.0.1:18080/rpc \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer local-development-token' \
  -d '{"jsonrpc":"2.0","id":1,"method":"flow.start","params":{"flow_key":"demo_approval","subject":{"type":"order","id":"demo-1"}}}'
```

The example flow pauses on `approve`. Finish it with:

```sh
curl -sS http://127.0.0.1:18080/rpc \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer local-development-token' \
  -d '{"jsonrpc":"2.0","id":2,"method":"flow.complete","params":{"flow_key":"demo_approval","subject":{"type":"order","id":"demo-1"},"step_id":"approve","idempotency_key":"approval-1","result":{"status":"completed","message":"Approved"}}}'
```

Use `flow.get` with the same `flow_key` and `subject` to read the persisted
state. `flow.definition` takes a `flow_key` and returns its definition. `flow.start`
is idempotent for a subject and flow key; `flow.complete` requires a stable
idempotency key. The RPC endpoint also accepts JSON-RPC batches and
notifications. Errors use JSON-RPC codes; internal exceptions are logged on
the server and returned as a generic error.

The example uses `mod/php/bootstrap.example.php` and creates the
`workflow_rpc_states` table via `mod/php/schema/postgres.sql`. To host real
flows, provide your own PHP bootstrap returning `FlowRpcApplication`, register
your `FlowDefinitionProvider` and `FlowStepExecutor` implementations, and
configure a `FlowStateStoreFactory`. The bootstrap path is selected with
`WORKFLOW_BOOTSTRAP`; the PHP document root is `WORKFLOW_PHP_PUBLIC`.
Build a derived image or mount trusted host code into the container rather
than accepting PHP code or flow definitions from RPC callers.

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

The module image build runs the pure-Go RPC tests and vet, then compiles the
PHP-linked binary in the PHP 8.3 FrankenPHP builder; the host may lack its
native PHP headers. Run the PHP suite in the repository's
PHP 8.3 test container with `make docker-test`. For a full HTTP smoke test,
start the Compose stack and send the requests above. Stop it with
`docker compose -f mod/compose.yml down` (the named PostgreSQL volume remains).
