---
status: accepted
---

# Authoring is a server-side validation module, not a client feature

We add a definition validator, a `workflow` CLI, a `flow.validate` JSON-RPC
method, and generated Node, Python, and Go contracts. Validation is one core
module; every surface presents its result. No client reimplements a rule, and
none of these additions executes or registers anything.

## Context

ADR 0008 made step outputs first-class and inputs declarative. ADR 0009 added
the operation catalog, schema hooks, and safe binding diagnostics. Together
they gave a host enough metadata to *describe* a flow, but an author still had
no way to ask "would this document run here?" without starting it.

The obvious shortcut — put validation in each language SDK — fails for three
reasons:

- Four copies of the rules drift; a document valid in one client becomes
  invalid at the server.
- The rules need host context (executors, descriptors, granted capabilities)
  that a remote client does not own.
- A client that validates locally cannot be authoritative, so the server must
  validate again anyway.

The opposite shortcut — a visual editor — was already ruled out. It would
introduce a UI, an HTTP execution surface for client-supplied code, and a
second source of truth for topology.

## Decision

### 1. One validator, consumed by RPC and the CLI

`FlowDefinitionValidator` accepts the canonical document (or a
`FlowDefinition`) plus optional executors and optional granted capabilities.
It returns a `FlowValidationReport` and never throws for a bad document.

Checks run in a fixed order: document shape, step ids, dependencies, cycles,
bindings, operation availability, capabilities, and optional schemas. After
the specific checks, the document is re-parsed with
`FlowDefinition::fromArray` as a backstop, so a document the runtime refuses
to construct is never reported valid.

`flow.validate` and `workflow validate` both call this class. The CLI adds no
rules; it parses arguments, loads an executor file once, and renders the
report.

### 2. Diagnostics are data

A `FlowDiagnostic` carries a stable `code`, a human-readable `message`, a
severity, an RFC 6901 JSON Pointer `path` into the submitted document, an
optional `step_id`, and structured `details`. `valid` is exactly "no
`error`-severity diagnostics"; warnings (`no_steps`, `operations_unchecked`,
`capabilities_unchecked`, `invalid_capability`, `invalid_schema`,
`unknown_output_property`, `invalid_binding_required`) inform without
blocking.

Diagnostics never contain resolved run values. They describe the document the
author submitted, which makes them safe to log and forward.

### 3. Skipped checks are announced

A validator with no executors cannot know what operations exist, so it says so
with `operations_unchecked`. A validator with no granted-capability list cannot
know whether requirements are satisfied, so it says `capabilities_unchecked`
when any requirement was found. Both are warnings; callers that need a strict
answer must supply the host context. RPC capabilities are bound when the host
constructs `FlowRpcApplication`; request callers cannot claim grants. The CLI
operator supplies them explicitly with `--capabilities` as local host context.

### 4. Operation discovery is a separate, description-only contract

`FlowOperationDirectory::operations()` lists what an executor publishes before
any definition exists; `FlowOperationProvider::operation()` describes the
operation behind a step. Both are authoring data. `FlowOperationCatalog` does
the per-step matching with the same `supports()` the runner uses. Neither
contract executes anything or grants a capability.

### 5. Clients carry types, not rules

`tools/contracts.php` is the single manifest for the wire shape. A small
generator emits `types/work-flow.d.ts`, `types/work_flow.py`, and
`types/work_flow.go`. `composer types` regenerates them; CI fails on drift.

Clients send `flow.validate` and render `FlowValidationReport`. They do not
hold executors, granted capabilities, or operation descriptors, so duplicating
the rules would only produce a second, less-informed answer.

### 6. The CLI is a presentation layer

`bin/workflow` loads the Composer autoloader and delegates to
`FlowAuthoringCli`, which takes output closures for testability. It supports
`validate`, `inspect`, and `operations`, text or `--json`, stdin via `-`, and
stable exit codes (`0` valid, `1` invalid/unparseable, `2` usage or input
error). It reaches no store and invokes no executor.

## Consequences

### Positive

- One authority for validity; every surface agrees by construction.
- Authors can validate before starting a run, in CI, or from any language.
- Generated contracts stay honest because a stale file is a failing test.
- The core stays framework-, transport-, and storage-neutral: the validator
  depends only on existing contracts and value objects.

### Negative

- A client's local report is only as complete as the host context it was
  given; `operations_unchecked` and `capabilities_unchecked` are visible
  reminders rather than silent omissions.
- Adding a check means touching one module and regenerating types for any new
  diagnostic fields; the CLI and RPC pick it up automatically.

### Neutral

- `flow.validate` is additive; existing methods and clients are unaffected.
- `types/work-flow.d.ts` is now generated. It was hand-written and unused;
  the generated file fixes the old `StepOutput` mismatch by typing the
  serialized `output` as `unknown`.
- The validator's backstop re-parse is deliberately redundant. It costs one
  construction of an already-validated document and guarantees that "valid"
  means "the runtime would accept it."

## References

- [ADR 0009: Safe diagnostics, optional schema hooks, an opt-in operation catalog, and wait deadlines](0009-safe-diagnostics-catalog-and-deadlines.md)
- [ADR 0008: First-class outputs and declarative input bindings](0008-first-class-outputs-and-declarative-input-bindings.md)
- [ADR 0001: Core is a coordination library](0001-core-is-a-coordination-library.md)
- [Authoring guide](../authoring.md)
