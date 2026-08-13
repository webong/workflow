# WebFlow

WebFlow is a framework-agnostic vocabulary and state evaluator for product
flows. It can describe connection setup, onboarding, conversation policies,
attention banners, and user actions without knowing about tenants, channels,
connections, queues, databases, or a particular UI framework.

The package deliberately does not execute jobs or persist state. The host
application supplies those adapters and stores the serialized state wherever
its domain requires.

## Core vocabulary

- `FlowDefinition`: a named flow and its step definitions.
- `StepDefinition`: a step's stable identity, label, dependencies, and policy.
- `FlowState`: the evaluated state of a flow and its step states.
- `FlowEvaluator`: derives a flow state from a definition and stored data.
- `FlowPresentation`: a UI-neutral banner/card/inline presentation.
- `FlowAction`: a UI-neutral action descriptor.
- `FlowDefinitionProvider`: resolves definitions supplied by the host domain.
- `FlowPresentationResolver`: turns state into a presentation for a surface.
- `FlowActionHandler`: lets the host application execute domain actions.
- `FlowContext` and `ArrayFlowContext`: provide host-owned runtime context.
- `FlowStepExecutor`: executes one domain step without coupling WebFlow to a
  queue or framework.
- `FlowRunner`: executes supported steps in order and respects dependencies.
- `FlowStateTransition`: produces immutable running/completed/failed/reset states.
- `FlowActionDispatcher`: delegates host-owned actions through a registry.
- `FlowPresentationFactory`: provides a neutral default attention presentation.
- `FlowRetryPolicy`: describes attempts, backoff, and idempotency.
- `FlowEvent`: serializable lifecycle event descriptor for host event buses.
- `FlowStateMigrationRunner`: upgrades persisted state between schema versions.
- `FlowActionAuthorizer`: lets the host enforce domain permissions before an
  action is dispatched.

The same vocabulary can represent a failed webhook setup step or a
conversation banner that asks an operator to use a template. Domain code owns
the meaning and execution of those actions.

## Host integration

The host maps its existing step definitions into `FlowDefinition`, reads its
stored metadata into `FlowState`, and provides one `FlowStepExecutor` per
domain step. `FlowRunner` returns a new evaluated state; the host persists it
and may dispatch each executor through its own queue system.

Definitions are versioned and validate duplicate ids, unknown dependencies, and
dependency cycles at construction time. Executor exceptions are converted into
failed step states using the step's retriable policy; the host can persist the
returned state and retry it later.

## TypeScript contracts

The package exposes `types/web-flow.d.ts` for consumers that want stable
frontend contracts immediately. When the development dependency is installed,
`composer types` regenerates `types/web-flow.generated.ts` using
`paneon/php-to-typescript`; the generated file is checked by CI.
