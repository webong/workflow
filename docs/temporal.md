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
