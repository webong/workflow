# Use the WorkFlow core from a native program

This is an **experimental build target**, not a replacement for the Composer
package or the [FrankenPHP JSON-RPC server](../README.md).
Start with [the PHP, Node, and Python guide](../../docs/languages.md) if you
are choosing how your application should connect.

TypePHP compiles the existing PHP core into:

- `workflow-native`: an executable that reads one JSON request from standard
  input and prints one JSON response.
- `libworkflow_native.so`: a shared library with a small C interface. The
  included Python example calls it in-process using `ctypes`, with no HTTP
  server or PHP subprocess.

CI builds and tests Linux AMD64 and ARM64. Other operating systems, musl,
static libraries, and fully static executables are not supported by this
pipeline. The artifacts still depend on compatible PHP/PHPX and system shared
libraries; copying just the executable or `.so` onto a clean machine will not
be sufficient. See [distributions and releases](../../docs/releases.md) for
the SDK archives, companion runtime images, and version-tag publishing.

## Build and test

From the repository root, with Docker running:

```sh
make native-test
```

This installs the compiler through Composer **inside the build image**, exports
reference cases using PHP 8.3, builds both native artifacts, and tests them from
Python, then exercises the executable from Node. It also checks the C
interface's buffer ownership, lifecycle, and
same-thread restrictions. Only generated step timestamps/retry deadlines are
normalized in the parity comparison; they come from each execution's clock.

The source checkout is mounted read-only. Artifacts and reference cases live
in the `workflow-native_native_build` Docker volume, at `/build` inside the
containers. No PHP, TypePHP, Python, or Node installation is required on your host.

Then try a complete approval flow from Python:

```sh
docker compose -f mod/typephp/compose.yml run --rm native \
  python3 mod/typephp/examples/python_client.py
```

The result should contain `"status": "completed"` and `"Approved from Python"`.
Read [the example](examples/python_client.py) to see how to initialize the
library, start a run, request work, submit its outcome, and release resources.

Or run the same approval from Node:

```sh
docker compose -f mod/typephp/compose.yml run --rm native \
  node mod/typephp/examples/node_client.mjs
```

This prints `"status": "completed"` and `"Approved from Node"`. The
[Node example](examples/node_client.mjs) calls the executable in a fresh
process for each operation; it does not load the shared library into Node.
It needs no npm dependencies. Its `call()` rejects process errors and JSON
domain errors; the lower-level Python `call()` returns domain errors with
`ok: false`, which your caller must check.

To call the executable instead:

```sh
printf '%s' '{"protocol":1,"operation":"start","run_id":"approval-1","definition":{"key":"approval","steps":[{"id":"approve"}]}}' | \
  docker compose -f mod/typephp/compose.yml run --rm -T native /build/workflow-native
```

Check `ok` in the JSON response. Domain errors return `ok: false`, even when
the executable itself exits successfully.

## What the host must do

Every request contains `protocol: 1` and an `operation`:

| Operation | Input | Result |
| --- | --- | --- |
| `start` | `run_id`, `definition` | A new pinned run state; no work is executed |
| `evaluate` | `state` | Recalculated progress |
| `advance` | `state` | Updated state and newly issued `work` items |
| `complete` | `state`, `completion` | State after accepting a deferred result |
| `cancel` | `state` | Cancelled state; no further work is issued |

Success responses contain `ok`, `state`, and `work`. Each work item contains
`flow_key`, `run_id`, `step_id`, and `attempt`. A completion uses those four
fields plus `idempotency_key` and `result`. Result status is `completed`,
`failed`, or `skipped`; optional fields are `message`, `error`, `retriable`,
and `metadata`.

For example, a Python application can implement an `email` step using its own
mailer. WorkFlow only decides when that step becomes eligible:

1. Load the latest state and call `advance`.
2. Atomically save the returned state **and the work items to deliver** before
   performing external work. Use your own locking/version checks and outbox.
3. Perform the work using a stable idempotency key derived from its run and
   step (and, where appropriate, attempt).
4. Load the latest state, apply `complete`, and atomically save its result.
5. Call `advance` again to obtain newly eligible dependent steps.

The library is stateless: it does not remember previous calls, deduplicate
`start`, save data, queue jobs, schedule retry deadlines, or contact providers.
Reusing an old state can reissue work. An `advance` on the latest waiting state
returns no duplicate work; persist the original work items so a host crash
does not lose them. Cancellation stops future work but does not undo an
operation the host has already started. See [run lifecycle](../../docs/run-lifecycle.md).

Use only trusted definitions and persisted snapshots. This in-process interface
is not a security sandbox and provides no subject/tenant authorization. Keep
host credentials out of snapshots and ensure explicit result messages are safe
for their intended audience. Optional Laravel and Temporal adapters are not
included in these native artifacts; there is no foreign-language callback
interface for arbitrary PHP executors or state stores yet.

## C interface and runtime ownership

Publish [workflow_native.h](include/workflow_native.h) with a library built for
the consumer's platform. The versioned entry points are:

```c
workflow_native_abi_version();
workflow_native_init();
workflow_native_call(input, input_size, &output, &output_size);
workflow_native_free(output);
workflow_native_shutdown();
```

- Initialize, call, and shut down on the **same OS thread**. Calls from another
  thread return `WORKFLOW_NATIVE_WRONG_THREAD`. Go consumers would need a
  dedicated locked OS thread; Go/Rust bindings have not been implemented.
- There is one process-global runtime. Do not create independent client
  instances, unload the library while active, or load it alongside another
  embedded PHP runtime. Shutdown is terminal; start another process to reopen.
- Inputs are UTF-8 JSON, capped at 1 MiB and a decode depth of 64. This is not
  protection against all resource exhaustion: a fatal runtime failure can
  terminate the host process. Prefer RPC when process isolation matters.
- A successful call returns a length-delimited, NUL-terminated buffer. Release
  it exactly once with `workflow_native_free`, including for JSON domain errors.
  Numeric C status codes represent ABI/runtime errors, not workflow outcomes.
- The library ignores host PHP ini files. It uses the built-in PHP facilities
  needed by the core, not host-configured extensions.

## Why a separate Composer manifest?

[`composer.json`](composer.json) declares `swoole/typephp: 0.9.3` in
`require-dev`, and the committed lockfile pins its transitive build dependencies.
TypePHP needs PHP 8.4+, so putting it in the root development dependencies would
break PHP 8.3 installs. The pinned build image uses PHP 8.5; the Composer package
and its regular suite still use PHP 8.3. Do not install the compiler as a runtime
dependency of applications using WorkFlow.

The compiler translates all core files. The C/JSON interface lives here; it
does not reimplement the flow rules in C++ or Python. One core expression is
written using a local `$attempts` variable because TypePHP 0.9.3 miscompiled a
coalesced argument inside a null-safe retry-policy call. The retry parity cases
guard this behavior. No compiler fork or generated-source patch is required.

The Dockerfile has separate `toolchain`, `release`, and `artifacts` targets.
Compose selects the development toolchain. CI builds a non-root runtime
image, unpacks the SDK archive into it, and reruns the parity and Node tests
without the compiler. The final image does not contain Node or Python.

Native releases remain experimental and need further memory/stress validation.
The SDK includes license notices and a dependency manifest; the Docker runtime
supplies PHP and system libraries. The root MIT license does not by itself
describe all linked third-party components; review their redistribution
requirements when building a derived product.

Upstream references: [TypePHP](https://github.com/swoole/typephp/tree/v0.9.3),
[C library example](https://github.com/swoole/typephp/tree/v0.9.3/examples/lib-demo).
