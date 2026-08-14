# Changelog

## Unreleased

- Renamed the package from `webong/web-flow` (`Webong\WebFlow`) to
  `webong/work-flow` (`Webong\WorkFlow`) to reflect its delivery-surface-neutral
  scope.
- Renamed `StepDefinition` to `FlowStepDefinition` for consistency with the
  package's other flow-specific public types.
- Added validated, versioned flow definitions and dependency graphs.
- Added retry metadata, attempts, backoff, and idempotency policy objects.
- Added lifecycle event descriptors and framework-neutral presentations/actions.
- Added state transitions, action dispatching, and dependency-aware execution.
- Added generated contracts for TypeScript consumers.
- Added state migration and action authorization contracts.
