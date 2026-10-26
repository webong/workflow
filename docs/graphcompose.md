# GraphCompose: current architecture and lessons for WorkFlow

Research date: 2026-10-24.

This note compares GraphCompose's current official documentation and public
repositories with WorkFlow's current contracts. It is an architectural
assessment, not a runtime verification, interoperability claim, or licensing
opinion. No hosted GraphCompose workflow was executed.

## Executive conclusion

GraphCompose is useful to us primarily as a product and contract-design
reference. Its strongest idea is not Temporal itself. It is the way one
declarative graph, one stable result namespace, and one schema-backed operation
catalog feed programmatic authoring, runtime validation, a visual builder, API
documentation, and AI-assisted discovery.

The parts worth reproducing independently are:

1. first-class structured step outputs;
2. explicit, validated input bindings from context and prior outputs;
3. an optional operation catalog with input/output schemas;
4. an inspectable but redacted binding-resolution trace; and
5. a clear distinction between authoring contracts, execution drivers, hosted
   features, and runtime-specific capabilities.

We should not make WorkFlow's core Temporal-dependent, copy GraphCompose's
JSONata semantics, accept arbitrary HTTP targets over the private RPC API, or
add a GraphCompose adapter merely because the products both execute DAGs.

## Core model and node contracts

GraphCompose describes a workflow as a JSON directed acyclic graph containing a
`nodes` array, optional global `context`, an optional `webhookUrl`, and
workflow-level settings. Nodes name dependencies explicitly; ready nodes can
run in parallel. The managed API validates a graph, starts it asynchronously,
returns workflow and run identities, and exposes status and accumulated
results. [Core concepts](https://app.graphcompose.io/docs/core-concepts),
[workflow execution](https://app.graphcompose.io/docs/workflow-execution).

Most business behavior remains outside GraphCompose. Its primary node calls an
HTTP endpoint owned by the user; GraphCompose supplies orchestration around
those calls. Specialized documented types include error boundaries,
confirmation nodes, source iterators and destinations,
`forEach`/`endForEach` child-workflow boundaries, and ADK agent nodes.
[Core package node types](https://github.com/graph-compose/core#node-types),
[forEach](https://app.graphcompose.io/docs/for-each).

The HTTP node is the central operation contract:

- method, URL, headers, and optional body describe the call;
- `activityConfig` supplies retry and timeout policy;
- `validation.input` and `validation.output` hold JSON Schemas;
- `conditions` can terminate, route, or poll; and
- `dependencies` control readiness independently of data expressions.

Runtime input validation checks the accumulated `results` before the request.
Output validation checks `result.data`, not the full HTTP envelope. Schema
failures are non-retryable and can be routed through an error boundary.
[Runtime validation](https://app.graphcompose.io/docs/validation).

Below the hosted node union, the execution kernel dispatches each node to a
`NodeHandler` registered for its type. Handlers receive workflow information,
activities, and state management. Lifecycle plugins observe workflow, batch,
and per-node events; subclasses can replace graph building, scheduling, state,
or the execution loop. [Execution
kernel](https://github.com/graph-compose/execution-kernel).

GraphCompose therefore has two different notions of operation:

- a runtime node type and handler; and
- an integration exposed as a standardized HTTP endpoint.

The `graph-compose/nodes` repository implements the latter in TypeScript and
Python. Zod/OpenAPI definitions generate `/openapi.json`; that description
feeds the API reference, visual catalog, and AI assistant. Most integrations
still run through ordinary HTTP nodes. [Nodes architecture and automatic
propagation](https://github.com/graph-compose/nodes#automatic-propagation).

## Input and output bindings

A completed node is stored at `results.<nodeId>` with a stable envelope:

```json
{
  "data": {},
  "statusCode": 200,
  "headers": {}
}
```

Downstream configuration uses JSONata templates such as
`{{ results.fetch_order.data.id }}`. Other namespaces include global
`context`, runtime-resolved `$secret()` values, and `row`/`rows` for
iteration; ADK has its own `state`. Resolution occurs just before execution.
[Template syntax](https://app.graphcompose.io/docs/template-syntax), [core
concepts](https://app.graphcompose.io/docs/core-concepts).

Bindings can appear in URLs, headers, bodies, and conditions. Conditions use
`data.*` for the current node result and `results.*` for accumulated
results. Termination is evaluated before routing; `pollUntil` repeats the node
according to retry policy until expressions pass or attempts are exhausted.
[Flow control](https://app.graphcompose.io/docs/flow-control).

Dependencies and references are separate declarations. Validation checks
identity, dependencies, cycles, connectedness, condition targets, loop
boundaries, node-specific structure, and expression syntax on client and
server. [Graph validation](https://app.graphcompose.io/docs/graph-validation).

One behavior should not be copied: the public runtime warns and continues when
an expression resolves to `undefined`; the debugging guide notes a failed
resolution can remain as the raw expression string. WorkFlow should make
unresolved required bindings fail closed. [Runtime expression
behavior](https://github.com/graph-compose/runtime#expression-syntax),
[expression debugging](https://app.graphcompose.io/docs/debugging-expressions).

## Package and runtime architecture

| Surface | Responsibility |
| --- | --- |
| `@graph-compose/client` | Fluent TypeScript builder, local validation, and calls to the managed API. |
| `@graph-compose/core` | Graph types, Zod schemas, validation, JSONata helpers, and shared constants. |
| `@graph-compose/execution-kernel` | DAG construction, ready-node scheduling, state, handlers, plugins, activities, and Temporal bindings. |
| `@graph-compose/runtime` | Self-hosted Temporal runtime for HTTP-only DAGs. |
| `graph-compose/nodes` | Schema-described integration endpoints and recipes. |

Sources: [library catalog](https://app.graphcompose.io/docs/libraries), [client
repository](https://github.com/graph-compose/client), [core
repository](https://github.com/graph-compose/core), [kernel
repository](https://github.com/graph-compose/execution-kernel), [runtime
repository](https://github.com/graph-compose/runtime), and [nodes
repository](https://github.com/graph-compose/nodes).

The self-hosted runtime validates an HTTP-only graph, builds a DAG, repeatedly
selects all ready nodes, executes each batch concurrently, records results, and
returns the aggregate. HTTP work and expression resolution are Temporal
activities. Per-node retry/timeout settings become activity options. Temporal
queries expose execution state and node results. [Runtime execution
model](https://github.com/graph-compose/runtime#execution-model).

The hosted product and public runtime are not equivalent. The runtime README
explicitly excludes hosted error boundaries, iterator child workflows, ADK,
confirmations, streaming, conditional routing, webhooks, secret lookup, visual
authoring, and a separate persistence facility. This does not negate Temporal
event-history durability; it defines the package boundary. [Runtime feature
boundary](https://github.com/graph-compose/runtime#what-this-package-does-not-do).

The kernel has extension seams, but is not runtime-neutral in WorkFlow's sense:
its manifest declares Temporal peer dependencies, its activities are shaped
around Temporal proxies, and it ships Temporal bindings. WorkFlow should retain
framework-neutral coordination in `src/` and optional Temporal integration in
`ext/Temporal`. [Kernel
manifest](https://github.com/graph-compose/execution-kernel/blob/main/package.json),
[Temporal bindings](https://github.com/graph-compose/execution-kernel#temporal-bindings).

## Durability, asynchronous work, and observability

Managed execution is asynchronous: start returns workflow/run identities and
`RUNNING`; callers poll status or receive a webhook. Status includes context,
executed IDs, and results and can represent cancellation, termination, timeout,
and Temporal continue-as-new. [Executing
workflows](https://app.graphcompose.io/docs/workflow-execution).

Temporal supplies durability. Long-running behavior uses:

- activity retries/timeouts and a workflow execution timeout;
- `pollUntil` for external jobs with status endpoints;
- child workflows for `forEach`;
- confirmation/ADK waits resumed through signals; and
- queries for current state.

Sources: [resiliency](https://app.graphcompose.io/docs/configuring-resiliency),
[forEach](https://app.graphcompose.io/docs/for-each), and [ADK queries and
signals](https://app.graphcompose.io/docs/agents/child-workflow-queries).

The current official material does not expose a general attempt-scoped
external completion callback comparable to WorkFlow's
`FlowDeferredCompletion`. Current integration guidance describes
asynchronous providers as start plus status endpoints, compatible with
`pollUntil`. The old `async-nodes` link previously cited here is no longer
in the current documentation set, so its task-token contract is not current
evidence. [Node contribution
guide](https://app.graphcompose.io/blog/how-to-contribute-a-node), [polling
semantics](https://app.graphcompose.io/docs/flow-control).

Webhooks are observation, not a durable bus: delivery is fire-and-forget, has
no retry, and does not affect execution. Receivers are expected to persist
quickly and be idempotent. [Webhooks](https://app.graphcompose.io/docs/webhooks).

Observability includes the status API, Temporal queries, node/completion
webhooks, kernel lifecycle hooks, and a dedicated expression-resolution
activity showing source templates, evaluation context, resolved values, and
warnings. [Kernel hooks](https://github.com/graph-compose/execution-kernel#executionplugin-lifecycle-hooks),
[expression debugging](https://app.graphcompose.io/docs/debugging-expressions).

The expression trace is useful but may contain context, outputs, bodies, and
secrets. WorkFlow should expose resolution provenance and errors while letting
hosts redact or omit values; event sinks must not receive an unconditional
context dump.

## Licensing

The public client, core, kernel, and runtime manifests declare
`AGPL-3.0-only`; their READMEs also describe a commercial license. The nodes
repository contains an AGPL-3.0 license and makes the same commercial-license
statement. [Client
manifest](https://github.com/graph-compose/client/blob/main/package.json),
[core manifest](https://github.com/graph-compose/core/blob/main/package.json),
[kernel manifest](https://github.com/graph-compose/execution-kernel/blob/main/package.json),
[runtime manifest](https://github.com/graph-compose/runtime/blob/main/package.json),
[nodes license](https://github.com/graph-compose/nodes/blob/main/LICENSE).

These repositories are references, not code sources for WorkFlow's MIT codebase.
Do not copy implementations or introduce dependencies without a licensing
review. This is not a legal conclusion about any particular deployment.

## Adopted lessons in current WorkFlow contracts

### 1. Step output is first-class

`StepResult` and `StepState` carry a domain-neutral `StepOutput` whose
presence is distinguishable from a JSON `null` value. It is persisted and
serialized across the native ABI, JSON-RPC, Laravel/PDO stores, and Temporal
adapter. The OWS adapter uses the same first-class field while retaining a
legacy metadata read/write path for older state. Output remains
transport-neutral; HTTP status and headers belong in adapter metadata.

### 2. Typed bindings do not select a framework or expression language

`FlowStepDefinition::dependsOn` defines ordering, while serializable
`FlowInputBinding` objects select data from run context and predecessor
outputs. Validation rejects unknown steps, forward references, and reads from
non-dependencies. Required unresolved bindings fail before an executor can
perform an external effect.

Core embeds no PHP callables and requires no expression language. RFC 6901
JSON Pointers cover ordinary selection; richer languages can sit behind the
optional resolver contract.

### 3. The operation catalog is separate from execution

`FlowStepExecutor::supports()` remains execution authority. The optional
`FlowOperationProvider`, `FlowOperationDirectory`, and
`FlowOperationCatalog` contracts describe stable operation keys, labels,
schemas, and capabilities. Laravel, CLI, RPC, documentation, Boost guidance,
or a future editor can consume the same descriptions.

The catalog describes host-approved executors. It must not turn arbitrary URLs
or client code into executable operations.

### 4. Binding resolution is observable and safe

`FlowEventSink` receives structured `input_resolved` and `input_unresolved`
outcomes containing the binding and source description, required flag, and
outcome. Resolved values are never included, so a failure identifies the
binding without exposing credentials or unrelated context.

### 5. Keep scheduling in the driver

GraphCompose shows the value of concurrent ready sets, but `FlowRunner`
should not become implicitly concurrent. `FlowReadySet` now exposes every
step eligible from one snapshot; inline, queue, Temporal, RPC, and native
hosts still decide how to atomically claim, limit, and run them.

### 6. Build on WorkFlow's existing deferred completion contract

WorkFlow already correlates completion to flow, run, step, attempt, and
idempotency key, while the RPC claim prevents overlapping passes. Preserve
that stronger generic callback contract. Ordinary wait deadlines use the
explicit `timedOut()` transition; scheduled OWS waits record a successful
outcome applied by `deadlineReached()`. Wakeups remain driver-owned; callbacks
are not replaced with polling and Temporal remains optional.

### 7. Child workflows are another run, not nested state

`FlowChildRunRequest` carries child identity plus the parent attempt identity,
and `FlowChildRunDispatcher` leaves definition lookup and execution with the
host. Awaited children reuse deferred completion; fire-and-forget children
continue after dispatch. Per-item aggregation and concurrency limits remain a
host policy rather than a second state model. An editor should still consume
the serialized definition and operation catalog rather than create another
workflow model.

## Adoption status

1. **Complete:** `StepOutput` in result/state serialization, persistence,
   migrations, and all cross-language/runtime surfaces (ADR 0008).
2. **Complete:** minimal bindings, a resolver interface, and validation for
   context and predecessor outputs (ADR 0008).
3. **Complete:** optional input/output schema hooks around executor calls
   (ADR 0009).
4. **Complete:** safe resolution events and diagnostics (ADR 0009).
5. **Complete:** an optional operation catalog exposed only where hosts
   authorize it (ADR 0009 and ADR 0010).
6. **Complete:** deadlines and explicit timeout transitions for deferred work
   (ADR 0009).
7. **Complete:** pure ready-set eligibility, host-dispatched child runs,
   standalone RPC selection of registered queue/Temporal drivers, and OWS
   wait/listen/child-workflow coordination (ADR 0011).
8. **Deferred deliberately:** a visual editor and arbitrary client-supplied
   execution remain out of scope; tooling consumes the server-owned catalog.

## What not to do

- Do not add a GraphCompose adapter without a concrete exchange requirement.
- Do not claim OWS compatibility from conceptual similarity.
- Do not replace optional Temporal with GraphCompose's coupled kernel.
- Do not standardize HTTP response fields as every step's output.
- Do not expose arbitrary HTTP execution through private JSON-RPC.
- Do not record complete contexts or secrets for convenient diagnostics.
- Do not build a visual editor before output, binding, schema, and catalog
  contracts stabilize.

Local context: [core is a coordination
library](adr/0001-core-is-a-coordination-library.md), [RPC is private and
host-controlled](adr/0003-rpc-is-private-server-to-server.md), [one runtime owns
authoritative state](adr/0004-execution-runtime-owns-authoritative-state.md),
and [callbacks target an attempt](adr/0005-callbacks-target-an-attempt-and-replay-starts-a-new-run.md).
