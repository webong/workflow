# Optional Temporal execution

Use Temporal when you need its long-running workflow execution model. It is
an execution runtime, **not** another implementation of `FlowStateStore`.
The adapter in `ext/Temporal` is optional and does not alter the framework-
neutral core.

## What the adapter provides

- `TemporalFlowExecutionDriver` converts a `FlowExecutionRequest` into a
  workflow ID and a serializable `TemporalFlowInput`, then calls a starter
  closure provided by the host.
- `TemporalFlowWorkflow` coordinates the deterministic workflow loop.
- `TemporalFlowActivity` delegates step work to host `FlowStepExecutor`
  implementations.
- `TemporalFlowCompletion` carries a deferred completion signal.
- The `snapshot` query exposes current state and a rejected-completion count
  without consulting an external state store.

The host must install `temporal/sdk`, configure its client and worker,
register the workflow and activity, and supply the SDK-specific start closure.
This package does not launch a Temporal server, create a worker process, or
automatically route individual steps there.

In a Laravel host, the published `config/work-flow.php` includes default
values for Temporal's address, namespace, and task queue. They are
configuration inputs for **your** client/worker bootstrap, not an automatic
connection:

```dotenv
WORK_FLOW_TEMPORAL_ENABLED=true
WORK_FLOW_TEMPORAL_ADDRESS=127.0.0.1:7233
WORK_FLOW_TEMPORAL_NAMESPACE=default
WORK_FLOW_TEMPORAL_TASK_QUEUE=work-flow
```

Choose the execution driver when starting a flow. The selected driver is
recorded in the state; changing it midway is rejected. A database or Redis
store may serve API/UI projections, but Temporal's event history owns the
Temporal execution. Keep HTTP, database, Redis, and other side effects inside
Activities, not deterministic Workflow code.

The standalone JSON-RPC module in [`mod/`](../mod/README.md) currently
accepts only `inline` for `flow.start`; setting Temporal environment variables
does not enable Temporal there.

## Start a distinct run

Create a `FlowRun` before dispatch and pass its `initialState()` in the
`FlowExecutionRequest`. Give each new occurrence its own ID. With a subject
and no explicit Temporal execution ID, `TemporalFlowIdentity` derives the
Workflow ID from subject, flow key, and logical run ID. An explicit execution
ID remains host-controlled and must also distinguish repeated runs.

The logical WorkFlow run ID is separate from Temporal's Workflow ID and its
server-generated Run ID. Keep all three when the host needs to address an
exact Temporal execution. The definition snapshot travels in workflow input
and is part of the history; callbacks do not load a newer definition.

Include `runId` and `attempt` when constructing `FlowDeferredCompletion`, then
serialize it with `TemporalFlowCompletion`. Send that payload to the existing
workflow's `complete` signal. Signals queue in arrival order per step. Invalid
or stale completions increment `rejected_completions`; an asynchronous signal
acknowledgement alone does not prove that the business completion was accepted.
Read the snapshot when the caller needs to confirm acceptance.

An existing deferred step waits without launching another Activity. A failed
step retries only with an eligible bounded `FlowRetryPolicy`, using Temporal
timers. Explicit non-retriable failures and `idempotent: false` stop retry.
Return `deferred()` for external waits; a plain `pending()` result ends that
step's execution pass without installing a signal wait.

Supply the same `FlowStepExecution`/`FlowFailureReporter` configuration to
`TemporalFlowActivityHandler` as to an inline runner for private diagnostics.
Unexpected exception details do not enter the returned step state.

Use Temporal's host cancellation API for a live Temporal execution. A
database/Redis projection is never a cancellation or callback authority.
Projection delivery and freshness remain host responsibilities.

Tests cover the SDK-neutral transport, inbox, and Activity handler. They do
not claim live Temporal Server/RoadRunner or history replay validation. Follow
the [official PHP SDK](https://github.com/temporalio/sdk-php) for worker setup
and replay tests before deploying a changed workflow implementation.
