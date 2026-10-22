# OWS Conformance Test Kit — adapter capability matrix

Source: open-workflow-specification/specification@v1.0.3 (`ctk/features/*.feature`), vendored under `ext/OpenWorkflow/Conformance/features`.

WorkFlow is a **limited OWS importer**, not a conforming OWS runtime (ADR 0006).

## Summary

| Status | Scenarios |
| --- | --- |
| static pass | 7 / 21 |
| static assertion failure | 0 / 21 |
| unsupported feature | 14 / 21 |
| validation failure | 0 / 21 |
| parse failure | 0 / 21 |
| **Total** | **21** |

## Scenarios

| Feature | Scenario | Status | Runtime | Blocking features / detail |
| --- | --- | --- | --- | --- |
| branch | Fork Task With Competing Concurrent Sub Tasks | unsupported feature | required | `fork (/do/0)` |
| call | Call HTTP Using Basic Authentication | static pass | required | — |
| call | Call HTTP With Content Output | static pass | required | — |
| call | Call HTTP With Response Output | static pass | required | — |
| call | Call OpenAPI With Content Output | static pass | required | — |
| call | Call OpenAPI With Response Output | static pass | required | — |
| data-flow | Input Filtering | unsupported feature | required | `set (/do/0)` |
| data-flow | Output Filtering | static pass | required | — |
| data-flow | Use Non-object Output | static pass | required | — |
| do | Task With Sequential Sub Tasks | unsupported feature | required | `set (/do/0/compositeExample/do/0)`, `set (/do/0/compositeExample/do/1)`, `set (/do/0/compositeExample/do/2)` |
| emit | Emit Task | unsupported feature | required | `emit (/do/0)` |
| flow | Explicit Sequence Flow | unsupported feature | required | `set (/do/0)`, `set (/do/1)`, `set (/do/2)` |
| flow | Implicit Sequence Flow | unsupported feature | required | `set (/do/0)`, `set (/do/1)`, `set (/do/2)` |
| for | For Task | unsupported feature | required | `for (/do/0)` |
| raise | Raise task with inline error | unsupported feature | required | `raise (/do/0)` |
| set | Set Task | unsupported feature | required | `set (/do/0)` |
| switch | Switch task with explicit default case | unsupported feature | required | `set (/do/1)`, `set (/do/2)`, `set (/do/3)`, `set (/do/4)` |
| switch | Switch task with implicit default case | unsupported feature | required | `set (/do/1)`, `set (/do/2)`, `set (/do/3)` |
| switch | Switch task with matching case | unsupported feature | required | `set (/do/1)`, `set (/do/2)`, `set (/do/3)` |
| try | Try Handle Caught Error | unsupported feature | required | `try (/do/0)` |
| try | Try Raise Uncaught Error | unsupported feature | required | `try (/do/0)` |

## Reading this matrix

- **static pass** — the document validates, translates to a `FlowDefinition` with no unsupported constructs, and every statically-checkable assertion (task ordering: *runs first / last / before / after*) holds against the translated execution order. It does **not** prove the workflow produces the CTK's expected output; that requires a runtime.
- **unsupported feature** — the document translates, but uses constructs the adapter explicitly rejects (documented in ADR 0006): `for`, `fork`, `wait`/`listen`, `try`/`catch`, `set`, `emit`, `raise`, sub-workflows.
- **validation failure** — our structural validator rejects the document. These are candidates for either a validator fix (if the construct is legal OWS) or a deliberate rejection (if it is in the unsupported set).
- **runtime required** — the scenario asserts completion, output, faults, or cancellation. Static translation cannot verify these; they become the test surface for a future executing runtime (Temporal-first, per ADR 0006).

## Path to conformance

The CTK guidance expects **all** scenarios to pass for conformance. This matrix intentionally publishes the gap rather than claiming compatibility. Each `unsupported feature` row maps to runtime work itemized in ADR 0006 (loops, concurrency, events, structured recovery); each `runtime required` row needs the executing runtime to exist before its assertions can run.
