# Changelog

## Unreleased

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
