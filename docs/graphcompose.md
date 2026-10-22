# GraphCompose: architecture and product research

Research date: 2026-10-21. This is an assessment, not an integration, runtime verification, or licensing opinion.

## Why it is relevant

GraphCompose is a workflow product built around JSON graphs of HTTP operations. Its website describes a managed Temporal deployment, visual authoring, API submission, and AI-assisted authoring. These are product claims; no hosted workflow was executed during this research. Its dependency-graph approach is conceptually closer to WorkFlow's current model than the broader Open Workflow Specification task language. [Official product overview](https://www.graphcompose.io/).

## The library split

The official library catalog separates authoring, graph structure, orchestration primitives, execution, and integrations. Those are useful seams to study, not a reason to create five new WorkFlow packages immediately. [Library catalog](https://www.graphcompose.io/docs/libraries).

| Package or repository | Documented responsibility |
| --- | --- |
| `@graph-compose/client` | Fluent TypeScript authoring and authenticated calls to GraphCompose's platform. |
| `@graph-compose/core` | Shared graph structures, schemas, validation, and expression utilities. |
| `@graph-compose/execution-kernel` | Graph construction, ready-node selection, state primitives, handlers, and execution hooks. |
| `@graph-compose/runtime` | A prepared Temporal implementation for HTTP graphs. |
| `graph-compose/nodes` | HTTP integration services and reusable recipes. |

The client is not a general SDK for arbitrary workflow servers. Its README describes authentication and execution against GraphCompose's own interface. The core graph format contains named nodes, explicit dependencies, initial context, and node configuration. Templates use JSONata rather than OWS's default jq expressions. [Client repository](https://github.com/graph-compose/client), [core repository](https://github.com/graph-compose/core).

## Runtime versus hosted product

The public runtime documents dependency-ordered HTTP calls, concurrent execution of independent nodes, expression resolution, per-operation retry/timeout settings, validation, and Temporal queries. Self-hosting requires a Temporal server and a worker with its SDK dependencies.

Its README explicitly separates this from the broader hosted product: grouped error recovery, iteration, agent workflows, approvals, streaming, conditional routing, webhook delivery, secret lookup, visual authoring, managed infrastructure, and a separate persistence capability are not supplied by that runtime package. Do not equate that persistence exclusion with an absence of Temporal's event-history durability.

Source: [runtime responsibilities, exclusions, and worker setup](https://github.com/graph-compose/runtime).

The kernel is extensible, but not runtime-neutral: its README requires Temporal workflow/common packages. It supports replacing scheduling/state behavior and adding operation handlers or lifecycle hooks. Its default approach repeatedly selects eligible nodes and dispatches them concurrently. This is not a drop-in replacement for our PHP coordination core or optional-runtime architecture. [Execution kernel](https://github.com/graph-compose/execution-kernel).

The source manifests inspected for runtime and client declare `AGPL-3.0-only`; both repository READMEs also describe commercial licensing. The core, kernel, and nodes repositories identify AGPL licensing as well. Do not treat these as MIT dependencies or copy code into our MIT package without reviewing the applicable terms. No conclusion about a specific integration's legal obligations is made here. [Runtime manifest](https://github.com/graph-compose/runtime/blob/main/package.json), [client manifest](https://github.com/graph-compose/client/blob/main/package.json), [core license information](https://github.com/graph-compose/core#license), [kernel license information](https://github.com/graph-compose/execution-kernel#license), [nodes license information](https://github.com/graph-compose/nodes#license).

## Ideas worth applying independently

- **Explicit data flow:** downstream operations can reference upstream results. That is a more useful next capability than adding another format parser while leaving result propagation implicit.
- **One operation catalog:** the nodes repository describes OpenAPI generated from schemas feeding documentation, the editor's operation catalog, and AI discovery. The architectural lesson is to maintain one definition of an operation's input/output contract rather than three manually synchronized inventories. [Nodes architecture](https://github.com/graph-compose/nodes#automatic-propagation).
- **Bounded asynchronous completion:** the platform documents an authenticated callback with an opaque, single-use, tenant-scoped task token; the wait has an explicit deadline, and callback output becomes the operation result. This is comparable to our deferred-attempt concept but is not the same protocol. Its timeout policy and callback ergonomics are worth studying. [Async nodes](https://www.graphcompose.io/docs/async-nodes).
- **Honest capability boundaries:** distinguish what the library, runtime, hosted product, and each adapter actually supply. A schema accepting a node type does not mean every runtime executes it.

## Relationship to Open Workflow Specification

No OWS compatibility claim or importer was found in the inspected library documentation and repository READMEs. GraphCompose's `nodes`/`dependencies` graph and JSONata templates differ from OWS's `document`/`do` task format and jq semantics. Similar purpose is not format compatibility. See our [OWS assessment](open-workflow-specification.md).

## Recommendation for WorkFlow

Use GraphCompose as an architecture and developer-experience reference, not another mandatory dependency or adapter by default. Keep OWS as the prospective standards-interoperability target.

A useful first milestone would be an end-to-end workflow in which one operation returns a structured result and another consumes it, with persisted/replayed data, typed input/output validation, and safe host-owned operation registration. Then add an HTTP executor with explicit host policy, secrets handling, timeouts, and idempotency rules. Better client ergonomics and an optional visual editor can build on those contracts.

Do not broaden the private RPC interface into unrestricted execution of client-supplied URLs or code without a separate authorization design. Keep Temporal optional, and preserve one authoritative execution state per run. These are proposed next steps, not accepted architecture changes or implemented features.

Local context: [coordination-library decision](adr/0001-core-is-a-coordination-library.md), [private RPC decision](adr/0003-rpc-is-private-server-to-server.md), [execution authority decision](adr/0004-execution-runtime-owns-authoritative-state.md).
