# Changelog

## Unreleased

- Added server-side definition authoring: `FlowDefinitionValidator` checks a
  canonical document's topology, bindings, operation availability, required
  capabilities, and optional operation schemas without executing, registering,
  or persisting anything. It returns structured `FlowDiagnostic` entries with
  stable codes and RFC 6901 JSON Pointer paths instead of throwing, and its
  report is valid only when no `error`-severity finding exists (ADR 0010).
- Added the `flow.validate` JSON-RPC method. The definition travels in the
  request and need not be registered; optional `capabilities` supply the
  host's grants, and bound operation providers supply operation and schema
  checks. One validator backs both this method and the CLI.
- Added the `workflow` CLI (`bin/workflow`): `validate`, `inspect`, and
  `operations`, with text or `--json` output, stdin support, an
  `--executors=file.php` host context, `--capabilities=a,b` grants, and
  stable exit codes. It is a presentation layer only: no rules, no execution.
- Added generated Node, Python, and Go contracts for the authoring and state
  surfaces (`types/work-flow.d.ts`, `types/work_flow.py`, `types/work_flow.go`)
  from one manifest, `tools/contracts.php`, via `composer types`. Clients
  carry types only; validation stays server-side.
- Added `FlowOperationDirectory`, an optional description-only contract for
  listing the operations a host publishes before a definition exists, and
  `examples/catalog-flow.php`, which discovers operations, validates a
  definition through `flow.validate`, starts it, completes a deferred step,
  and resumes to structured outputs.

- Added safe binding-resolution diagnostics: `input_resolved` /
  `input_unresolved` events carry binding names and source descriptions,
  never resolved values (ADR 0009).
- Added an optional schema hook: hosts wiring a `FlowStepSchemaValidator`
  get fail-closed input validation before the executor runs and terminal
  output-violation failures instead of persisted violating outputs. The
  core interprets no schema language.
- Added the optional operation catalog: executors may implement
  `FlowOperationProvider` to publish stable operation descriptors
  (key, label, summary, input/output schemas, capabilities);
  `FlowOperationCatalog::describe()` collects them per step. The JSON-RPC
  service exposes them read-only via `flow.operations`, opt-in by binding
  providers.
- Added wait deadlines for deferred attempts: `StepResult::deferred(...,
  deadlineAt: $epoch)` records a first-class `deadline_at` on step state,
  and `FlowStateTransition::timedOut()` is the explicit host-owned timeout
  transition. Core never expires work on its own clock.
- Added first-class structured step output: `StepResult` and `StepState`
  carry a transport-neutral `StepOutput` whose presence is distinguishable
  from a JSON null. Absent output serializes no `output` key; old state
  documents keep loading. Output is carried through synchronous and deferred
  completions, retries, all state stores, the JSON-RPC `flow.complete`
  request/response, Temporal activities and signals, generated TypeScript
  contracts, and native parity fixtures.
- Added declarative, serializable input bindings on `FlowStepDefinition`
  (JSON Pointer paths over the run context or a declared dependency's
  output). Required unresolved bindings fail closed before an executor runs;
  optional unresolved bindings are absent from the resolved input; unknown
  source steps and non-dependency references are rejected at definition
  time.
- Changed `FlowStepExecutor::execute()` to receive one typed
  `FlowStepInvocation` (step definition, resolved `FlowStepInput`, context,
  previous state, flow/run identity, attempt). Resolved input travels only
  through the invocation — never metadata. See the upgrade notes in
  [run lifecycle](docs/run-lifecycle.md) and ADR 0008.
- The OWS adapter writes task outputs as first-class `StepOutput` (keeping
  the legacy `ows_output` metadata write) and reads step outputs through
  `OwsOutputs`, which prefers first-class output and falls back to older
  `ows_output` metadata.
- Added an experimental elephc build target under `mod/elephc/` that compiles
  the same core to native code with no embedded PHP runtime, with a generated C
  header, a C consumer, and `make elephc-build` / `make elephc-test` host
  commands. Not yet built in CI.
- Extracted the compiler-agnostic native protocol and PHP 8.3 parity fixtures
  into `mod/native/`, which both the TypePHP and elephc targets now compile, so
  the wire contract has a single source.
- Moved the JSON-RPC application from `mod/` to `app/` (`Webong\WorkFlow\App`)
  so `mod/` holds only TypePHP native builds.
- Added Linux AMD64/ARM64 native and RPC CI builds, clean-runtime and HTTP
  smoke tests, SDK/runtime artifacts, checksums, and version-tag-gated GHCR
  and GitHub Release publishing. Fixed fresh-checkout RPC builds and the
  inherited Caddy health check; added the package's declared MIT license text.
- Added an experimental Composer-managed TypePHP build under `mod/typephp/`
  with native executable/shared-library targets, a C interface, runnable
  Node/Python examples, a language integration guide, and behavioral parity
  checks against PHP 8.3.
- Added explicit `FlowRun` identity, pinned definition snapshots, run-scoped
  storage, cancellation, and attempt-correlated deferred completions.
- Fixed deferred replay, dependency ordering, permanent failure retry, exception
  backoff, and optional pending-step evaluation.
- Added safe exception reporting through `FlowFailureReporter`; action dispatch
  now requires a host authorizer.
- RPC now requires `run_id` (and `attempt` for completions), supports explicit
  resume/cancel, and executes outside locks with persisted execution claims.
- Temporal signals use a bounded FIFO and expose a snapshot query; runtime
  history remains authoritative.
- Added a channel-setup example, upgrade/lifecycle guidance, and PHPStan level 9.

- Renamed the package from `webong/web-flow` (`Webong\WebFlow`) to
  `webong/workflow` (`Webong\WorkFlow`) to reflect its delivery-surface-neutral
  scope.
- Renamed `StepDefinition` to `FlowStepDefinition` for consistency with the
  package's other flow-specific public types.
- Added validated, versioned flow definitions and dependency graphs.
- Added retry metadata, attempts, backoff, and idempotency policy objects.
- Added lifecycle event descriptors and framework-neutral presentations/actions.
- Added state transitions, action dispatching, and dependency-aware execution.
- Added generated contracts for TypeScript consumers.
- Added state migration and action authorization contracts.
