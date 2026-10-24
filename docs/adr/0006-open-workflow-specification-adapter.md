---
status: proposed
---

# Open Workflow Specification adapter as an optional module

We add OWS support through an optional `ext/OpenWorkflow` module, not by changing the core coordination model or adding a new state-store driver. The module pins one specification version, validates documents, reports unsupported constructs explicitly, and executes a small, well-defined subset with correct data-flow semantics.

## Context

The Open Workflow Specification (OWS) defines a vendor-neutral workflow language with JSON/YAML definitions, schemas, and multiple runtime implementations. Supporting it would let callers author workflows in a shared format instead of learning our PHP objects or proprietary serialized definition.

Key constraints from research:

- **Version pinning is mandatory.** The homepage advertises DSL 1.0.3; the v1.0.3 GitHub release is marked pre-release; `main` already uses 1.0.4 in examples. We must pin an exact version and source revision (e.g., `specification@v1.0.3` or a specific commit SHA).
- **The linked PHP SDK targets 0.8** and does not execute workflows. Installing it would not give us current OWS support; we must implement validation and execution ourselves.
- **OWS execution semantics are richer than our current model:** ordered tasks with `then`/`switch`, automatic task-output-to-next-input pipeline, `for` iteration, `fork` concurrency, `wait`/`listen` for external events, `try`/`catch` with retry policies, and `call`/`run` for protocol and script execution. Our current `FlowDefinition` is a fixed acyclic step graph with integer versioning — these are not equivalent models.
- **Conformance requires the full CTK.** The specification provides a Conformance Test Kit (Gherkin scenarios). A schema-valid document or a handful of passing scenarios is not evidence of conformance.

## Decision

### Module placement and scope

- Create `ext/OpenWorkflow` as an optional package (similar to `ext/Laravel` and `ext/Temporal`).
- It depends on the core contracts (`FlowDefinitionProvider`, `FlowStepExecutor`, `FlowExecutionDriver`) but does not replace them.
- It is **not** a state-store driver. State stores implement `FlowStateStore`; this module translates OWS documents into core definitions and executes them through existing drivers.

### Version and schema

- Pin **OWS DSL 1.0.3** at the tagged release `specification@v1.0.3` (commit `a1b2c3d...`). Update only by explicit decision with migration tests.
- Bundle the JSON Schema (`workflow.json`) for validation. Use a PHP JSON Schema validator (e.g., `justinrainbow/json-schema` or equivalent) — no external service calls during validation.

### Validation and capability reporting

- Accept an OWS document (JSON or YAML) and return a **CapabilityReport**:
  - `valid: bool` — schema validation passed
  - `version: string` — the pinned OWS version used
  - `supported: string[]` — feature identifiers we execute (e.g., `sequential-task`, `switch`, `then:exit`)
  - `unsupported: {feature: string, path: string, reason: string}[]` — every construct we reject, with its JSON Pointer path and reason
  - `warnings: string[]` — non-blocking issues (e.g., deprecated fields)
- **Never silently discard** unsupported constructs. Rejection is explicit and documented.

### Supported subset (initial milestone)

Only the following are executed. Everything else is reported as unsupported.

| Feature | OWS construct | Execution behavior |
|---------|---------------|-------------------|
| Sequential tasks | `do: [task1, task2, ...]` | Tasks run in declaration order; each task's transformed output becomes the next task's raw input. |
| Conditional routing | `switch` / `case` / `default` / `then` | Evaluate `switch` expression; run matching `case` body; `then` targets `exit`, `end`, or a task in the current scope. |
| Task execution | `call`, `run` (HTTP/script/container) | Map to host-provided `FlowStepExecutor` implementations. Expression evaluation uses `jq` semantics via a safe evaluator (no arbitrary code). |
| Identity | `document.namespace`, `document.name`, `document.version` (semver) | Preserved without coercion to integer. Registered under a composite key `namespace/name@version`. |

**Explicitly excluded from initial scope:** `for` (iteration), `fork` (concurrency), `wait`/`listen` (durations/events), `try`/`catch` (structured error handling), `run` (sub-workflow), lifecycle events, expressions beyond what the safe evaluator supports.

### Data flow implementation

- The OWS rule "task output → next task input" is **not** provided by our current dependency graph. The adapter must implement this pipeline explicitly:
  - Each executed step receives the previous step's transformed output as its `FlowContext` input.
  - `export.as` replaces the workflow context; the adapter tracks this separately from step state.
  - Expression evaluation (input/output transformations, `switch` conditions) uses a `jq`-compatible evaluator with defined error behavior.

### Host-controlled registration

- Definition registration remains host-controlled. The RPC contract does **not** become an unrestricted workflow-upload or code-execution endpoint.
- The module exposes a `registerOwsDefinition(string $yamlOrJson): CapabilityReport` method; the host decides whether to persist the resulting `FlowDefinition` and make it runnable.

### Execution-aware data subset (delivered)

The adapter's first executing capability is the OWS 1.0.3 data-flow model for
`set` tasks, per the specification's Data Flow section:

- Task raw input = the chain predecessor's transformed output; the first
  task's raw input is the workflow input. Translation records the chain in
  `ows_previous` metadata and orders the chain following `then` directives
  (`then: end`/`exit` terminate it).
- `input.from` filters a task's raw input; the filtered value is the jq `.`
  for the task's expressions.
- `set` values are literals or `${ ... }` runtime expressions; the set task's
  output is the resulting map.
- The workflow output is the last task's transformed output; the workflow
  context is untouched (no `export.as` in this subset).

Execution runs through the core FlowRunner unchanged. To let step outputs
propagate between steps without breaking core isolation, the core gained an
opt-in `FlowMutableContext` contract: hosts passing such a context get a
write-through overlay view in `FlowStepExecution`; read-only contexts keep
the previous isolated-snapshot behavior (ADR 0001 boundary preserved).
`ext/OpenWorkflow/Runtime` provides `OwsWorkflowContext` (implements the
contract, records the output chain) and `OwsSetStepExecutor`. CTK scenarios
covering this subset execute end-to-end against their pinned expected output
(`tests/OpenWorkflow/OwsRuntimeTest.php`).

### Conformance fixtures

- Vendor the pinned CTK scenarios as test fixtures (delivered: `ext/OpenWorkflow/Conformance/features/`, `specification@v1.0.3`).
- Build a test harness that binds CTK steps to the adapter (delivered: `CtkParser` + `CtkRunner`, run by `tests/OpenWorkflow/CtkConformanceTest.php`; regenerate the matrix with `php tools/generate-ctk-matrix.php`).
- Publish a **supported-feature matrix** documenting exactly which CTK scenarios pass, which are intentionally unsupported, and why (delivered: `docs/open-workflow-ctk-matrix.md`, pinned by test so it cannot go stale).
- Describe the initial product as a **limited OWS importer**, not a conforming OWS runtime.

Current CTK posture (see the matrix for detail): 13 of 21 scenarios validate,
translate, and hold their statically-checkable assertions; 2 more (switch
routing) translate but their ordering assertions depend on runtime case
evaluation; 6 remain blocked by constructs outside the subset (`for`, `fork`,
`try`/`catch`, `emit`, `raise`). Scenarios covered by the data subset execute
end-to-end against their pinned expected output; full conformance still
requires the remaining runtime capabilities.

### Future work (not in this ADR)

- Loops (`for`), concurrency (`fork`), event correlation (`wait`/`listen`), structured recovery (`try`/`catch`), sub-workflows (`run`), and lifecycle events require additional runtime capabilities in the core or execution drivers.
- Full CTK conformance is a separate product milestone.
- The TypePHP native target (`mod/typephp`) compiles the core and excludes optional adapters. This module would not be available through the native binary/shared library unless explicitly included in the build.

## Consequences

### Positive

- Callers can author workflows in a standard format without learning WorkFlow internals.
- Clear boundary: validation is separate from execution; unsupported features are documented, not ignored.
- The core coordination model remains stable; optional runtime capabilities can grow independently.

### Negative

- Initial subset is small. Teams expecting full OWS support will be disappointed unless the supported-feature matrix is consulted.
- Maintaining the pinned version and CTK fixtures adds ongoing maintenance.
- Expression evaluation (`jq`) requires a safe, well-tested implementation or a carefully sandboxed dependency.

### Neutral

- Does not affect existing Laravel, Temporal, or standalone RPC deployments.
- Does not change `FlowDefinition`, `FlowRun`, or `FlowStateStore` contracts.

## Resolved decisions (from the open questions)

- **Expression evaluator**: `wikimedia/zest-jq` as the pure-PHP backend, with an automatic fallback chain that prefers a system `jq` binary when present (`EvaluatorBackend` / `MultiBackendEvaluator`). Full jq 1.8+ semantics (regex, `reduce`, modules) come free where the binary exists; zest-jq covers the import subset everywhere else. Known zest-jq gaps (no `not()`, no `test()` regex) are reported as unsupported expressions.
- **YAML**: accepted, via `symfony/yaml`; JSON and YAML share one parse path.
- **Registration API**: `registerOwsDefinition` returns the `CapabilityReport` including the translated `FlowDefinition`; persistence stays with the host. The RPC surface (`ows.validate` / `ows.register` on `FlowRpcApplication`) exposes this only when the host binds an `OpenWorkflow` instance — opt-in.
- **Namespace collision**: OWS identities register under the composite key `namespace/name@semver` (e.g. `com.acme/order@1.0.0`); native flows keep the host's `key@integer` format. The key spaces are disjoint by construction; hosts wanting both for the same logical flow manage the mapping themselves.
- **Version pinning**: pinned to the tagged `specification@v1.0.3`. `main` already carries 1.0.4 examples but no stable release exists; re-pinning is a deliberate decision with migration tests.

## Delivered runtime capabilities (beyond import)

- **Data flow (1.0.3 model)**: `set` tasks with `input.from` filtering, output-chain semantics, `then`-ordered chains, composite flattening — `ext/OpenWorkflow/Runtime`, executed through the core FlowRunner via the opt-in `FlowMutableContext` contract.
- **Switch routing**: case evaluation (first matching `when`; a case without `when` is the explicit default), routing decisions recorded per switch, unselected branches skip, `then: end`/`exit` termination, and the CTK-pinned behavior that an unmatched switch yields the unmodified input as workflow output.
- **Iteration (`for`)**: data-only bodies execute in-process per item with the named item (`each`) and default `$index` bound as runtime-expression arguments; each iteration's input is the previous iteration's output, so bodies accumulate. Non-data bodies are rejected explicitly.
- **Fork (`fork`)**: data-only branches execute with `compete: true` first-branch-wins semantics (deterministic for data tasks) or ordered execution otherwise. True parallelism belongs to the executing runtime.
- **Structured recovery (`try`/`catch`)**: body tasks flatten scoped for fault recording (non-critical — the catch decides the run's fate); catch handlers are data-only chain roots gated by runtime faults, with the error bound to the catch's variable name and CTK-pinned `instance` document paths; unmatched errors fault the workflow.
- **Emit (`emit`)**: builds the CloudEvent envelope (generated id/time, specversion 1.0, evaluated source/type/data) as task output; actual delivery stays host-owned.
- **Raise (`raise`)**: faults the workflow with the error payload and its document-path `instance`; faults raised inside a try scope are catchable.
- Deliberately still unsupported: `wait`/`listen` (durable timers and event correlation require a durable runtime — Temporal or a host scheduler) and sub-workflows (`run: workflow`).

## References

- [Open Workflow Specification research note](../open-workflow-specification.md)
- [ADR 0001: Core is a coordination library](0001-core-is-a-coordination-library.md)
- [ADR 0002: Runs have identity and pinned definitions](0002-runs-have-identity-and-pinned-definitions.md)
- [ADR 0003: JSON-RPC is private server-to-server](0003-rpc-is-private-server-to-server.md)
- [ADR 0004: Execution runtime owns authoritative state](0004-execution-runtime-owns-authoritative-state.md)