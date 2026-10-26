---
status: accepted
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

- Pin **OWS DSL 1.0.3** at the tagged release `specification@v1.0.3`. Update only by explicit decision with migration tests.
- Bundle the JSON Schema (`workflow.json`) as the versioned reference and perform local structural validation. Validation makes no external service calls.

### Validation and capability reporting

- Accept an OWS document (JSON or YAML) and return a **CapabilityReport**:
  - `valid: bool` — schema validation passed
  - `version: string` — the pinned OWS version used
  - `supported: string[]` — feature identifiers we execute (e.g., `sequential-task`, `switch`, `then:exit`)
  - `unsupported: {feature: string, path: string, reason: string}[]` — every construct we reject, with its JSON Pointer path and reason
  - `warnings: string[]` — non-blocking issues (e.g., deprecated fields)
- **Never silently discard** unsupported constructs. Rejection is explicit and documented.

### Shipped execution subset

The adapter currently translates and executes the following capabilities:

| Feature | OWS construct | Execution behavior |
|---------|---------------|-------------------|
| Sequential tasks | `do: [task1, task2, ...]` | Tasks run in declaration order; each task's transformed output becomes the next task's raw input. |
| Conditional routing | `switch` / `case` / `default` / `then` | Evaluate `switch` expression; run matching `case` body; `then` targets `exit`, `end`, or a task in the current scope. |
| Data transforms | `set`, `input.from`, `output.as` | Apply jq-compatible transforms and pass the transformed output to the next task. |
| Iteration and branching | data-only `for`, `fork` | Iterate in-process; forks are deterministic and do not promise parallel execution. |
| Recovery and faults | `try`/`catch`, `raise` | Record OWS faults and route matching catches. |
| Events | `emit` | Build a CloudEvent envelope; delivery remains host-owned. |
| HTTP operations | `call: http`, `call: openapi` | Execute through a host-provided PSR-18 client. |
| Durable waits | `wait` | Persist a successful wake deadline; the host scheduler or Temporal timer applies it. |
| Event waits | `listen` | Persist the consumption strategy; a host event adapter supplies the correlated completion. |
| Child workflows | `run: workflow` | Dispatch through a host `FlowChildRunDispatcher`; awaited children defer the parent attempt. |
| Identity | `document.namespace`, `document.name`, `document.version` (semver) | Preserved without coercion to integer. Registered under a composite key `namespace/name@version`. |

**Still excluded:** built-in scheduling/event infrastructure, container and shell execution, true parallel fork execution, lifecycle events, and expressions the selected jq backend cannot evaluate. Host-bound wait/listen/child coordination was added by ADR 0011; unsupported constructs are still reported and never silently discarded.

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

Current CTK posture (see the matrix for detail): all 21 scenarios in the
complete pinned v1.0.3 feature set validate, pass their statically-checkable
assertions, and link to named end-to-end runtime tests. This is still not an
external conformance certification: host-bound capabilities and deployment
behavior remain the host's responsibility.

### Future work (not in this ADR)

- True parallel fork execution, lifecycle events, and additional protocol/process task types require more runtime capabilities.
- Host schedulers and event adapters may build richer correlation and subscription policies on the delivered wait/listen seams.
- Full CTK conformance is a separate product milestone.
- The TypePHP native target (`mod/typephp`) compiles the core and excludes optional adapters. This module would not be available through the native binary/shared library unless explicitly included in the build.

## Consequences

### Positive

- Callers can author workflows in a standard format without learning WorkFlow internals.
- Clear boundary: validation is separate from execution; unsupported features are documented, not ignored.
- The core coordination model remains stable; optional runtime capabilities can grow independently.

### Negative

- The adapter is deliberately incomplete. Teams must consult the supported-feature matrix before accepting arbitrary OWS documents.
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
- **HTTP transport (`call: http`, `call: openapi`)**: `OwsHttpExecutor` runs protocol calls over any PSR-18 client (host-owned transport; adapter-owned semantics) — method, endpoint URI with simple `{placeholder}` interpolation, headers, query, JSON body, basic/bearer authentication with runtime expressions, `content`/`response` output modes, task-base `output.as` filtering with `$input` bound, and non-2xx responses faulting as OWS communication errors (catchable). `call: openapi` resolves the document, operationId, and parameters through the same client.
- Host-bound durable coordination: `wait` records a successful deadline transition, `listen` records a subscription for an event adapter, and `run: workflow` dispatches through `FlowChildRunDispatcher` (ADR 0011). None of these installs a scheduler, broker, or child-definition registry.

## References

- [Open Workflow Specification research note](../open-workflow-specification.md)
- [ADR 0001: Core is a coordination library](0001-core-is-a-coordination-library.md)
- [ADR 0002: Runs have identity and pinned definitions](0002-runs-have-identity-and-pinned-definitions.md)
- [ADR 0003: JSON-RPC is private server-to-server](0003-rpc-is-private-server-to-server.md)
- [ADR 0004: Execution runtime owns authoritative state](0004-execution-runtime-owns-authoritative-state.md)
