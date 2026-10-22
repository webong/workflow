# Open Workflow Specification: compatibility research

Research date: 2026-10-21. This is an assessment, not an implemented feature or conformance claim.

## What it offers

Open Workflow Specification (OWS) defines a vendor-neutral workflow language, with JSON/YAML definitions, schemas, SDKs, diagram tooling, and multiple runtime implementations. It could give WorkFlow a shared authoring format rather than require every caller to learn our PHP objects or proprietary serialized definition. It is not itself a queue, state store, or hosted execution service. [Project overview](https://open-workflow-specification.org/), [ecosystem roles](https://github.com/open-workflow-specification/specification/blob/main/README.md#ecosystem).

## Version and SDK caveats

- The homepage advertises DSL **1.0.3** and publishes a [JSON Schema](https://open-workflow-specification.org/schemas/1.0.3/workflow.json) using JSON Schema Draft 2020-12. `document` and `do` are required; document identity includes namespace, name, and semantic version.
- The [v1.0.3 GitHub release](https://github.com/open-workflow-specification/specification/releases/tag/v1.0.3) is marked pre-release, while current `main` examples already use `1.0.4`. Pin an explicit version and source revision; do not treat `main` as the release contract.
- This is the renamed Serverless Workflow project. The tagged 1.0.3 documents still use that name; current documentation explains the rebrand and lifecycle-event namespace transition. [Tagged DSL](https://github.com/open-workflow-specification/specification/blob/v1.0.3/dsl.md), [current lifecycle-event guidance](https://github.com/open-workflow-specification/specification/blob/main/dsl.md#lifecycle-events).
- The linked [PHP SDK](https://github.com/open-workflow-specification/sdk-php) explicitly targets **specification 0.8**, not 1.0.3. Its documented role is building, parsing, and validating definitions, not executing them. Installing it would not supply a current OWS runtime. Its README is evidence of the compatibility gap, not proof of project abandonment.

## Execution semantics that cannot be reduced to renaming fields

The following observations use the tagged 1.0.3 specification:

- Tasks execute in declaration order unless control flow redirects them. `then` targets are limited to the current scope.
- A task's transformed output becomes the next task's raw input. Workflow context is separate and is replaced through `export.as`; input/output validation and transformations occur at specified stages.
- `jq` is the required default expression language. Implementations need expression arguments and defined error behavior, not just string substitution.

Source: [DSL concepts, task/data flow, and expressions](https://github.com/open-workflow-specification/specification/blob/v1.0.3/dsl.md).

| Construct | Required meaning relevant to an adapter |
| --- | --- |
| `do`, `switch`, `then` | Ordered tasks, conditional routing, and scoped continuation/exit/end. |
| `for` | Collection iteration with per-item execution, not a fixed one-time node. |
| `fork` | Concurrent branches; either ordered branch outputs or one winning output when racing. |
| `wait`, `listen` | Duration waits and external-event consumption/correlation, including one/any/all strategies. |
| `try`, `catch`, retry policies | Structured error filtering, recovery, attempt/duration limits, backoff, and jitter. |
| `call`, `run` | Protocol calls and execution of scripts, containers, shell commands, or other workflows. |

Source: [tagged DSL reference](https://github.com/open-workflow-specification/specification/blob/v1.0.3/dsl-reference.md). Supporting a parsed document does not establish these runtime behaviors.

## Conformance and safety

The project provides a [Conformance Test Kit](https://github.com/open-workflow-specification/specification/blob/main/ctk/README.md): Gherkin scenarios with Given/When/Then steps that implementations bind to their own test runner. Its guidance expects all scenarios to pass for conformance. A schema-valid document or a handful of passing scenarios is not sufficient evidence for full compatibility. No OWS tests were executed during this research.

Current [expression guidance](https://github.com/open-workflow-specification/specification/blob/main/dsl.md#runtime-expressions) explicitly separates executable expressions in definitions from untrusted input data. An adapter should preserve that separation. Network calls, remote document resolution, secrets, shell commands, and containers also need explicit host authorization and resource limits; adding a parser must not silently grant those capabilities.

## Proposed direction for WorkFlow, not an accepted design

The current [FlowDefinition](../src/ValueObjects/FlowDefinition.php) uses a fixed acyclic step graph and a positive integer version. That is not the same model as OWS nested task scopes and semantic versions. The current runner also does not provide the OWS previous-task-output pipeline automatically; that behavior must be implemented and tested, not inferred from dependency ordering.

1. Keep the existing coordination model and optional runtime adapters. Add OWS support through an optional module such as `ext/OpenWorkflow`, not a new state-store driver.
2. Start with a validator and capability report against a pinned specification version. Reject unsupported features with document paths; never silently discard them.
3. Prove a small execution-aware subset before offering imports. Sequential calls still require correct data propagation and output semantics. Preserve OWS namespace/name/semantic-version identity without coercing it into a lossy integer.
4. Keep definition registration host-controlled. The current RPC contract need not become an unrestricted workflow-upload or code-execution endpoint.
5. Build fixtures from the pinned CTK and publish a precise supported-feature matrix. Describe the initial product as a limited OWS importer, not a conforming OWS runtime.
6. Treat loops, nested scopes, event correlation, races, cancellation, and recovery as later runtime work. A full interpreter is a larger product decision, not a small serialization adapter.
7. Validate each delivery target separately. The [TypePHP native target](../mod/typephp/README.md) compiles the core, excludes optional adapters, and has no arbitrary PHP executor callbacks. A PHP extension module would not automatically become available through the native binary or shared library.

Repository context: [core coordination ADR](adr/0001-core-is-a-coordination-library.md), [run identity ADR](adr/0002-runs-have-identity-and-pinned-definitions.md), [private RPC ADR](adr/0003-rpc-is-private-server-to-server.md), [authoritative state ADR](adr/0004-execution-runtime-owns-authoritative-state.md).
