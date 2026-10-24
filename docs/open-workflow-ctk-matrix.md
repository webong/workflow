# OWS Conformance Test Kit — adapter capability matrix

Source: open-workflow-specification/specification@v1.0.3 (`ctk/features/*.feature`), vendored under `ext/OpenWorkflow/Conformance/features`.

WorkFlow is a **limited OWS importer**, not a conforming OWS runtime (ADR 0006).

## Summary

| Status | Scenarios |
| --- | --- |
| static pass | 21 / 21 |
| static assertion failure | 0 / 21 |
| unsupported feature | 0 / 21 |
| validation failure | 0 / 21 |
| parse failure | 0 / 21 |
| **Total** | **21** |

## Scenarios

| Feature | Scenario | Status | Runtime | Blocking features / detail |
| --- | --- | --- | --- | --- |
| branch | Fork Task With Competing Concurrent Sub Tasks | static pass | required | — |
| call | Call HTTP Using Basic Authentication | static pass | required | — |
| call | Call HTTP With Content Output | static pass | required | — |
| call | Call HTTP With Response Output | static pass | required | — |
| call | Call OpenAPI With Content Output | static pass | required | — |
| call | Call OpenAPI With Response Output | static pass | required | — |
| data-flow | Input Filtering | static pass | required | — |
| data-flow | Output Filtering | static pass | required | — |
| data-flow | Use Non-object Output | static pass | required | — |
| do | Task With Sequential Sub Tasks | static pass | required | — |
| emit | Emit Task | static pass | required | — |
| flow | Explicit Sequence Flow | static pass | required | — |
| flow | Implicit Sequence Flow | static pass | required | — |
| for | For Task | static pass | required | — |
| raise | Raise task with inline error | static pass | required | — |
| set | Set Task | static pass | required | — |
| switch | Switch task with explicit default case | static pass | required | — |
| switch | Switch task with implicit default case | static pass | required | — |
| switch | Switch task with matching case | static pass | required | — |
| try | Try Handle Caught Error | static pass | required | — |
| try | Try Raise Uncaught Error | static pass | required | — |

## Reading this matrix

- **static pass** — the document validates, translates to a `FlowDefinition` with no unsupported constructs, and every statically-checkable assertion (task ordering: *runs first / last / before / after*) holds against the translated execution order. This status alone does **not** prove the workflow produces the CTK's expected output; the separate runtime suite exercises the delivered execution behavior.
- **unsupported feature** — the document uses a construct the adapter explicitly rejects. Current deliberate gaps are durable `wait`/`listen`, sub-workflows (`run: workflow`), and expression forms unsupported by the selected jq backend; the detail column names the blocker.
- **validation failure** — our structural validator rejects the document. These are candidates for either a validator fix (if the construct is legal OWS) or a deliberate rejection (if it is in the unsupported set).
- **runtime required** — the scenario asserts completion, output, faults, or cancellation, which static translation cannot verify. `tests/OpenWorkflow/OwsRuntimeTest.php` separately exercises the shipped in-process executors, including HTTP/OpenAPI through a mock PSR-18 transport.

## Path to conformance

The CTK guidance expects **all** scenarios to pass for conformance. This matrix publishes exactly where the adapter stands: every scenario translates and the full executing subset (data flow, routing, iteration, forks, recovery, emit, raise, HTTP/OpenAPI calls) is verified end-to-end against pinned expected outputs. Remaining gaps are documented, not hidden: durable `wait`/`listen` and sub-workflows await the durable runtime (ADR 0006), and a conformance claim would additionally require running the complete CTK suite with its own runner.
