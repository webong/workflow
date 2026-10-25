---
status: accepted
---

# Step outputs are first-class and inputs bind declaratively

We add a transport-neutral `StepOutput` value object to `StepResult` and
`StepState`, and a serializable, JSON-Pointer-based input binding contract to
`FlowStepDefinition`. Executors receive a typed `FlowStepInvocation` carrying
the step definition, the resolved `FlowStepInput`, the run context, the
previous step state, the flow/run identity, and the attempt. Structured data
never travels through step metadata.

## Context

Executors could return only status, message, error, retry data, and metadata.
The OWS adapter proved the need for structured data flow: it carries task
outputs through `ows_output` metadata and an in-memory
`OwsWorkflowContext` (ADR 0006). Metadata is execution bookkeeping, not a
domain-data transport — hosts reading step results could not distinguish a
step's business output from adapter internals, and cross-runtime surfaces
(RPC, Temporal, native) had no stable place to carry data. [The GraphCompose
research note](../graphcompose.md) reached the same conclusion: first-class
outputs and validated input bindings are the foundation every later schema,
catalog, and editor feature builds on.

## Decision

### 1. Output presence is a wrapper, not a nullable convention

`StepState::$output` is `?StepOutput`; `null` means *no output* and a
`StepOutput` wrapping `null` means *a present JSON null*.

- Serializing an absent output **omits** the `output` key; serializing a
  present null writes `"output": null`.
- Deserializing accepts a missing key (legacy documents), a JSON value, or an
  explicit null, and keeps the distinction intact.
- Output values must stay JSON-compatible (scalars, arrays, null) so every
  store, RPC surface, and native runtime serializes them unchanged.
- Metadata is never the new transport. The OWS adapter now writes both
  first-class output and its legacy `ows_output` metadata; the legacy read
  path (`OwsOutputs`) prefers first-class output and falls back to metadata.

The same rule applies everywhere outputs travel: `StepResult::toState()`,
`FlowStepExecution`, `FlowStateTransition` (including deferred completions,
whose duplicate fingerprint now includes output presence), Temporal activity
results and signal payloads, JSON-RPC `flow.complete` request/response, and
the native `complete` operation. A result's output replaces the previous
attempt's output on every retry, matching how status and metadata already
behave.

### 2. Bindings are declarative, serializable, and fail closed

`FlowStepDefinition::$input` maps binding names to `FlowInputBinding`
values. A binding reads either the run `FlowContext` (`source: context`) or
the first-class output of a step declared in the owning step's `dependsOn`
(`source: output`). Paths are RFC 6901 JSON Pointers; the empty pointer
selects the whole document. RFC rules are implemented exactly: `~1` unescapes
to `/` before `~0` unescapes to `~`, and list indexes accept the canonical
decimal form only.

- `FlowDefinition` rejects unknown source steps and references to steps that
  are not declared dependencies, at construction — same treatment as unknown
  `dependsOn` entries.
- `FlowInputResolver` runs before an executor is chosen to run. Required
  bindings that cannot resolve throw and the executor is never invoked, so an
  unresolved value can never silently become a side effect. Optional
  unresolved bindings are simply absent from the resolved input (never
  null-filled).
- The core binding representation carries no expression language, no PHP
  callables, and no host adapters. JSONata/jq remain optional concerns behind
  future resolver interfaces.

### 3. Executors receive one typed invocation

`FlowStepExecutor::execute()` takes a single `FlowStepInvocation` instead of
`(FlowStepDefinition, FlowContext, StepState)`. This is a breaking interface
change, taken deliberately at this milestone rather than growing a positional
signature with every future need (schemas, catalogs, deadlines). Resolved
input travels only through `$invocation->input` — never through context
metadata or an `_input` key. The documented `_workflow` context metadata
stays during the transition so existing callback-correlation code keeps
working.

Native compilation evidence did not force a smaller design: TypePHP and
elephc compile typed value objects and interface dispatch of exactly this
shape today, and the known elephc blockers (loop return-type inference,
exported class-method calls) are independent of the invocation design. The
new value objects stay inside the compilers' supported subset (readonly
classes, promoted properties, `match`, named arguments).

### 4. Temporal and RPC carry the same shape

The Temporal workflow resolves bindings against workflow history before
dispatching an activity (deterministic on replay; unresolved required
bindings fail the workflow before the activity runs) and passes the resolved
input in the activity payload. Signal payloads, activity results, and
`flow.complete` params/responses serialize output presence with the same
omit-or-carry rule.

## Consequences

### Positive

- Every runtime surface now shares one serialization for step data; the
  two-step contract (emit output → bind input) is exercised end-to-end by
  ordinary PHP state, persistence, RPC, Temporal, generated TypeScript, and
  native parity fixtures.
- Later milestones (schemas, catalogs, editor, redacted resolution events)
  can build on stable `StepOutput` and binding shapes without another
  migration.

### Negative

- `FlowStepExecutor` implementations must be updated (upgrade note in the
  run-lifecycle guide). External executors written against the old
  three-argument signature break at the type level, not silently.
- Persisted definitions gain an optional `input` object; definitions without
  bindings serialize exactly as before, so pinned runs from older versions
  keep validating against their snapshots.

### Neutral

- State stores need no schema changes: outputs live inside the serialized
  state document.
- `FlowState::SCHEMA_VERSION` stays 1; old documents load because the new
  field is optional and additive.

## References

- [GraphCompose research: lessons for WorkFlow contracts](../graphcompose.md)
- [ADR 0001: Core is a coordination library](0001-core-is-a-coordination-library.md)
- [ADR 0006: Open Workflow Specification adapter](0006-open-workflow-specification-adapter.md)
