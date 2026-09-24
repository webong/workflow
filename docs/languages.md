# Use WorkFlow from PHP, Node, Python, or another language

WorkFlow decides **what step can happen next**. Your application performs
the actual work: send an email, charge a card, or wait for approval. You do
not have to rewrite those operations in PHP to try the native module.

There are three ways to connect. They share the same PHP flow rules, but
they do not have the same hosting or persistence contract.

| Integration | How your application calls it | Who saves state? |
| --- | --- | --- |
| PHP library | PHP objects installed with Composer | Your PHP application, using a store interface |
| JSON-RPC module | HTTP from any trusted backend | The server; its example uses PostgreSQL |
| Experimental native module | Local executable or shared C library | Your application, after every transition |

Use the **PHP library** in PHP applications. Use **JSON-RPC** when several
backends need one server-owned state store. Try **native** when a Node or
Python application should own the workflow state and business operations
locally, without making an HTTP call.

## PHP: install the library

```sh
composer require webong/workflow
```

Define your steps with `FlowDefinition` and `FlowStepDefinition`, implement
`FlowStepExecutor` for your business operations, and pass them to `FlowRunner`.
Save its returned state through your chosen store. Laravel is optional.

The [order approval example](../examples/order-approval.php) is a complete
start → callback → resume sequence. Run it from this repository with Docker:

```sh
docker compose -f docker-compose.test.yml up -d php postgres redis
docker compose -f docker-compose.test.yml exec -T php composer install
docker compose -f docker-compose.test.yml exec -T php php examples/order-approval.php
```

It ends with `After resume: completed; notify: completed`. Follow the
[PHP guide](php-library.md) to replace its in-memory store and simulated
operations, or the [Laravel guide](laravel.md) for database/Redis integration.

PHP consumers need neither TypePHP nor a native build. Do **not** load
`libworkflow_native.so` into an existing PHP process using FFI: this library
owns its own embedded PHP runtime. It is not a PHP extension.

## Node and Python: build the native experiment once

From the repository root:

```sh
make native-test
```

Docker builds the executable and shared library, compares both against PHP
8.3 reference cases, and runs the Node integration tests. The build container
includes Python and Node 22; you do not need them installed on your host.

The current builds were tested on **Linux ARM64**, inside Docker. They need
the matching PHP/PHPX and system shared libraries. There are no published npm
or pip packages, portable release binaries, or validated static libraries yet.
These are source examples, not production SDKs.

### Node: call the executable

```sh
docker compose -f mod/typephp/compose.yml run --rm native \
  node mod/typephp/examples/node_client.mjs
```

The [complete Node example](../mod/typephp/examples/node_client.mjs) starts
an approval, asks for work, and reports `Approved from Node`. Its reusable
`call()` function uses Node's asynchronous
[`execFile()`](https://nodejs.org/api/child_process.html#child_processexecfilefile-args-options-callback)
without a shell:

```js
import { call } from './node_client.mjs';

const started = await call('start', {
    run_id: 'approval-42',
    definition: { key: 'order-approval', steps: [{ id: 'approve' }] },
});
const waiting = await call('advance', { state: started.state });
console.log(waiting.work); // The approve step, with its run ID and attempt.
```

Copy the client next to your Node entry point when using this import. Its
default executable path is `/build/workflow-native` in the example container;
pass a trusted absolute path as the third argument to `call()` elsewhere.
JSON goes through stdin, not command-line arguments. Each call starts a fresh
process, with a ten-second timeout and a four-MiB output limit. Keep that
per-call startup cost in mind; no Node in-process binding is provided yet.

`call()` throws on process failure **or** `ok: false`. Catch errors in your
backend and do not expose private process diagnostics to end users.

### Python: call the shared library in-process

```sh
docker compose -f mod/typephp/compose.yml run --rm native \
  python3 mod/typephp/examples/python_client.py
```

The [complete Python example](../mod/typephp/examples/python_client.py) uses
[`ctypes`](https://docs.python.org/3/library/ctypes.html) to load
`libworkflow_native.so` and reports `Approved from Python`. There is no HTTP
request or PHP subprocess. To use the wrapper in your own program, copy it
next to your entry point:

```python
from python_client import WorkflowNative

workflow = WorkflowNative("/build/libworkflow_native.so")
try:
    response = workflow.call("start", run_id="approval-42", definition={
        "key": "order-approval", "steps": [{"id": "approve"}],
    })
    if not response["ok"]:
        raise RuntimeError(response["error"]["message"])
    print(response["state"])
finally:
    workflow.close()
```

Initialize once, make all calls on the **same OS thread**, and close once
at process shutdown. Do not create a client per web request or pass it into
a thread pool. Closing is terminal: reopening requires a new process.
The wrapper releases each returned C buffer for you. C/runtime errors raise;
workflow errors return `ok: false` and must be checked after **every** call.

Python can also call the executable through a subprocess if it needs process
isolation instead of loading the library. Native crashes can terminate a
process that has loaded the shared library.

## What happens when a native step runs?

Imagine `approve` followed by `send-receipt`:

1. `start` creates a run state from your JSON definition. Save it under a
   unique run ID; native `start` does not check for an existing run.
2. `advance` returns the updated state and an `approve` work item.
3. Your application atomically saves that state **and an outbox entry for the
   work item**, then delivers the work outside the transaction.
4. When approval arrives, load the latest state and call `complete` with the
   work item's `flow_key`, `run_id`, `step_id`, `attempt`, a stable event
   `idempotency_key`, and the result. Atomically save the returned state.
5. Call `advance` again. It now returns `send-receipt`; your Node/Python
   mailer sends it and completes that step in the same way.

Use locks or compare-and-swap/version checks so concurrent callers cannot
overwrite each other's transitions. WorkFlow returns work descriptions; it
does not call your Node/Python functions, own their credentials, persist
state, deliver the outbox, or wake up when retry backoff ends. Your host owns
those responsibilities and must deduplicate external effects. The runnable
examples keep state in memory for learning; they are not durable workers.

See the [native protocol and runtime contract](../mod/typephp/README.md#what-the-host-must-do)
for all operations, result fields, and restrictions.

## Any language: use the JSON-RPC server instead

Node, Python, PHP, Go, or another backend can use an HTTP client to call the
[JSON-RPC module](../mod/README.md). That guide includes Docker startup,
authentication, and complete `curl` requests you can translate to your client.

The difference matters: RPC calls reference a server-registered `flow_key`
and a subject/run identity. The server loads and saves state and runs trusted
PHP executors. Native calls carry the whole state snapshot and return work
for the caller to perform. Native `protocol: 1` messages are **not** JSON-RPC
2.0 requests and cannot be sent unchanged to `/rpc`.

Keep RPC private to trusted backends. Browser/mobile apps should call their
own authorized backend, never receive the service bearer token. If Node or
Python performs deferred work for an RPC-backed flow, the server's PHP
executor must arrange that handoff; RPC does not expose a generic work-polling
queue. The receiving backend reports the outcome through `flow.complete`.

For a future in-process Go, Rust, or Node binding, use the
[C header and ownership contract](../mod/typephp/README.md#c-interface-and-runtime-ownership).
Those wrappers still need to be implemented and tested; the C interface alone
does not make them supported SDKs.
