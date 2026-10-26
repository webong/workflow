# OWS Conformance Test Kit — adapter capability matrix

Source: open-workflow-specification/specification@v1.0.3 (`ctk/features/*.feature`), vendored under `ext/OpenWorkflow/Conformance/features`.

WorkFlow is a **limited OWS runtime**; this matrix is implementation evidence, not an external certification claim (ADR 0006).

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
| branch | Fork Task With Competing Concurrent Sub Tasks | static pass | verified | — |
| call | Call HTTP Using Basic Authentication | static pass | verified | — |
| call | Call HTTP With Content Output | static pass | verified | — |
| call | Call HTTP With Response Output | static pass | verified | — |
| call | Call OpenAPI With Content Output | static pass | verified | — |
| call | Call OpenAPI With Response Output | static pass | verified | — |
| data-flow | Input Filtering | static pass | verified | — |
| data-flow | Output Filtering | static pass | verified | — |
| data-flow | Use Non-object Output | static pass | verified | — |
| do | Task With Sequential Sub Tasks | static pass | verified | — |
| emit | Emit Task | static pass | verified | — |
| flow | Explicit Sequence Flow | static pass | verified | — |
| flow | Implicit Sequence Flow | static pass | verified | — |
| for | For Task | static pass | verified | — |
| raise | Raise task with inline error | static pass | verified | — |
| set | Set Task | static pass | verified | — |
| switch | Switch task with explicit default case | static pass | verified | — |
| switch | Switch task with implicit default case | static pass | verified | — |
| switch | Switch task with matching case | static pass | verified | — |
| try | Try Handle Caught Error | static pass | verified | — |
| try | Try Raise Uncaught Error | static pass | verified | — |

## Reading this matrix

- **static pass** — the document validates, translates to a `FlowDefinition` with no unsupported constructs, and every statically-checkable assertion (task ordering: *runs first / last / before / after*) holds against the translated execution order. This status alone does **not** prove the workflow produces the CTK's expected output; the separate runtime suite exercises the delivered execution behavior.
- **runtime verified** — a named PHPUnit test translates and executes that exact pinned scenario through the adapter and asserts its runtime outcome. `CtkConformanceTest` prevents missing scenarios or stale test-method links.
- **unsupported feature** — the document uses a construct the adapter explicitly rejects. Current deliberate gaps are expression forms unsupported by the selected jq backend and host capabilities that were not bound; the detail column names the blocker.
- **validation failure** — our structural validator rejects the document. These are candidates for either a validator fix (if the construct is legal OWS) or a deliberate rejection (if it is in the unsupported set).
- **runtime required** — the scenario asserts completion, output, faults, or cancellation but has no linked runtime test.

## Path to conformance

All 21 scenarios in the complete pinned v1.0.3 CTK are linked to end-to-end runtime tests. WorkFlow still describes itself as a limited importer/runtime rather than claiming certification: the upstream project does not publish a separate executable runner or certification result, and host-bound capabilities remain deployment-specific. WorkFlow extensions for durable `wait`/`listen` and `run: workflow` are tested separately because v1.0.3 has no CTK scenarios for them.
