---
status: accepted
---

# Safe diagnostics, optional schema hooks, an opt-in operation catalog, and wait deadlines

We complete the structured-dataflow foundation from ADR 0008 with four
small, independent contracts. None of them makes an expression language, a
schema library, or a scheduler a core dependency, and none turns described
operations into executable behavior.

## Decision

### 1. Resolution diagnostics never carry values

Binding resolution reports structured outcomes through the existing
`FlowEventSink`: `input_resolved` and `input_unresolved` events carry the
binding name, its source description (`FlowInputBinding::describe()`), the
required flag, and the outcome (`resolved` / `omitted` / `failed`) — never
the resolved value. Resolved inputs can hold credentials or customer data,
so events are safe to forward to observability backends by default. A
required unresolved binding still fails closed; the event is recorded
before the exception.

### 2. Schema hooks are an optional contract, not a core dependency

`FlowStepSchemaValidator` lets a host (or an optional extension) check
resolved input and step output against schemas from wherever the host
keeps them — an operation descriptor, step metadata, or host configuration.
Runtime execution interprets neither the schema form nor the violations.
ADR 0010 later permits the authoring-only validator to read the optional
`required` and `properties` hints from operation descriptors; that shallow
linting never validates runtime values and does not change this execution
boundary.

- Input violations throw before the executor is invoked (fail closed, same
  class as unresolved required bindings).
- Output violations are checked after the executor ran. The effect already
  happened, so the step converts to a terminal failed result
  (`error_code: output_schema_violation`) instead of persisting the
  violating output. Retrying is the host's explicit decision.

### 3. The operation catalog describes; it never executes

`FlowOperation` is a serializable descriptor: stable operation key, label,
summary, input/output schema documents (opaque to runtime execution), capabilities,
and metadata. Executors may implement `FlowOperationProvider::operation()`;
`FlowOperationCatalog::describe()` collects descriptors per step using the
same `supports()` matching the runner uses, so a descriptor always
describes the executor that would actually run the step.

The RPC surface gains `flow.operations`, available only when the host binds
operation providers — the same opt-in rule as OWS registration (ADR 0006).
The catalog is authoring and documentation data: it grants no permissions,
uploads nothing, and never becomes a code-execution endpoint.

### 4. Deadlines are declared data; timeouts are explicit transitions

`StepResult::deferred()` may declare `deadlineAt` — the absolute wait
deadline for that attempt. It rides first-class on `StepState`
(`deadline_at`, omitted when unset, like `output`) and is visible through
every state surface.

Core never expires work on its own clock: there is no background scheduler
and the evaluator consults no clock (ADR 0001). The host or its driver owns
the wakeup and calls the new `FlowStateTransition::timedOut()` transition,
which turns the waiting attempt into a terminal non-retriable failure
("Wait deadline exceeded." by default) and re-evaluates the run. A callback
that arrives after the deadline but before the host applies the transition
is still accepted — the deadline is advisory data, and core does not guess
about external providers. Reconciliation stays with the host.

## Consequences

### Positive

- Every roadmap item from the GraphCompose lessons that can be built on the
  M1 contract now has a core shape; none of them forced a new dependency.
- Failure modes stay symmetric with M1: diagnostics are safe by default,
  schema failures prevent side effects (input) or persist nothing (output).

### Negative

- Deadline enforcement is entirely host-scheduled; hosts that never call
  `timedOut()` get recorded-but-unused deadlines.
- The catalog requires executors to opt in per step; executors that skip
  `FlowOperationProvider` are invisible to tooling (deliberate).

### Neutral

- New `FlowEventType` cases and `StepState::deadline_at` are additive;
  old consumers and old state documents keep working.
- Deferred work still has no automatic wakeup — that remains
  driver-owned by design.

## References

- [ADR 0008: First-class outputs and declarative input bindings](0008-first-class-outputs-and-declarative-input-bindings.md)
- [ADR 0006: Open Workflow Specification adapter](0006-open-workflow-specification-adapter.md)
- [GraphCompose research: lessons for WorkFlow contracts](../graphcompose.md)
