# Use Open Workflow Specification definitions

WorkFlow can validate, translate, and execute a practical subset of Open
Workflow Specification (OWS) DSL 1.0.3. Use this adapter when workflow authors
already have OWS JSON or YAML, while keeping WorkFlow's state stores and
execution drivers unchanged.

OWS is a definition format. It is not a queue, state store, or hosted workflow
server. The adapter lives under `ext/OpenWorkflow`, but ships in the same
`webong/workflow` Composer package.

## Install

The core package is enough for JSON definitions:

```sh
composer require webong/workflow
```

For YAML definitions, also install the optional parser:

```sh
composer require symfony/yaml
```

The expression engine prefers a system `jq` binary when one is available and
falls back to the included pure-PHP `wikimedia/zest-jq` backend. Production
hosts should install `jq` when they need its broader expression support.

For `call: http` or `call: openapi`, provide a PSR-18 client and PSR-17
request/stream factory. For example:

```sh
composer require guzzlehttp/guzzle guzzlehttp/psr7
```

## Validate and translate a definition

`registerOwsDefinition()` does not save anything. It returns a capability
report and translated `FlowDefinition`; the host decides whether to accept and
persist that definition.

```php
use Webong\WorkFlow\OpenWorkflow\OpenWorkflow;

$ows = new OpenWorkflow();
$report = $ows->registerOwsDefinition($jsonOrYaml);

if (! $report->valid) {
    throw new RuntimeException('The OWS document is invalid');
}

if ($report->unsupported !== []) {
    foreach ($report->unsupported as $problem) {
        printf("%s at %s: %s\n", $problem->feature, $problem->path, $problem->reason);
    }

    throw new RuntimeException('The document needs unsupported capabilities');
}

$definition = $report->flowDefinition;
```

The translated key preserves the OWS identity as
`namespace/name@semantic-version`, for example
`com.example/order-approval@1.0.0`.

## Run a data workflow in PHP

OWS execution uses the normal `FlowRunner`, but it needs an
`OwsWorkflowContext` and the executors for the task types the definition uses.
This complete example runs a `set` task:

```php
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\OpenWorkflow\OpenWorkflow;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsSetStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsWorkflowContext;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\ValueObjects\FlowState;

$document = <<<'YAML'
document:
  dsl: '1.0.3'
  namespace: com.example
  name: size-label
  version: '1.0.0'
do:
  - makeLabel:
      set:
        label: ${ .width * .height }
YAML;

$report = (new OpenWorkflow())->registerOwsDefinition($document);
if (! $report->valid || $report->unsupported !== [] || $report->flowDefinition === null) {
    throw new RuntimeException('OWS definition cannot run');
}

$context = new OwsWorkflowContext(['width' => 6, 'height' => 7]);
$state = (new FlowRunner())->run(
    $report->flowDefinition,
    new FlowState(FlowStatus::PENDING),
    $context,
    [new OwsSetStepExecutor()],
);

assert($state->status === FlowStatus::COMPLETED);
assert($context->lastOutput() === ['label' => 42]);
```

`OwsWorkflowContext` is a transient execution view, not another state store.
When resuming after a durable wait, event, or child result, rebuild it from the
original workflow input and saved state so output chaining and switch routing
continue correctly:

```php
$context = OwsWorkflowContext::resume($originalInput, $definition, $state);
$state = $runner->run($definition, $state, $context, $executors);
```

The Temporal Activity handler performs this reconstruction from workflow
history automatically.

Register only the executors you intend the host to permit:

- `OwsSetStepExecutor` and `OwsSwitchStepExecutor` for data flow and routing.
- `OwsForExecutor` and `OwsForkExecutor` for data-only iteration and branches.
- `OwsRaiseExecutor` plus `OwsFaultRecordingExecutor` for faults and catches.
- `OwsEmitExecutor` to build a CloudEvent envelope. It does not deliver the
  event; delivery is the host's responsibility.
- `OwsHttpExecutor` for HTTP/OpenAPI calls. Its PSR-18 client owns timeouts,
  TLS, redirects, and transport policy. Wrap it in `OwsFaultRecordingExecutor`
  when OWS catches must handle HTTP failures.
- `OwsWaitExecutor` to persist a successful wake deadline. A host scheduler
  calls `FlowStateTransition::deadlineReached()`; the Temporal adapter uses a
  durable timer for the same transition.
- `OwsListenExecutor` to persist the event consumption strategy. The host
  matches and authenticates events, then sends an ordinary deferred
  completion whose output is the consumed event data/envelope.
- `OwsWorkflowRunExecutor` with a host `FlowChildRunDispatcher` for
  `run.workflow`. Awaited children defer the parent step; fire-and-forget
  children let it continue after dispatch.

Do not accept arbitrary documents merely because they validate. The executor
list is the host's capability and security boundary.

## Enable OWS methods over JSON-RPC

The RPC service exposes `ows.validate` and `ows.register` only when the trusted
bootstrap opts in:

```php
use Webong\WorkFlow\OpenWorkflow\OpenWorkflow;

return new FlowRpcApplication(
    definitions: $definitions,
    stores: $stores,
    execution: $execution,
    ows: new OpenWorkflow(),
);
```

Then send a string `document` parameter:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "ows.validate",
  "params": {"document": "{...OWS JSON...}"}
}
```

`ows.validate` returns the report without translating. `ows.register` returns
the report with `flow_definition`, but despite the method name it still does
not persist or activate the definition. Your trusted host must review the
capability report and register the translated definition itself. The bundled
demo bootstrap leaves both methods disabled.

## Supported and unsupported behavior

The shipped runtime covers the 21 vendored scenarios in the pinned 1.0.3
CTK (all 11 feature files at the official tag):

- sequential `do` / `then` flow and `switch` routing;
- `set`, task input filtering, task output filtering, and output chaining;
- data-only `for` and deterministic `fork` behavior;
- `try` / `catch`, `raise`, and fault propagation;
- CloudEvent envelope creation for `emit`;
- `call: http` and `call: openapi` through a host PSR-18 transport.
- durable coordination contracts for `wait`, `listen`, and `run.workflow`;
  their scheduler, event broker, and child definition lookup are host-bound.

The adapter still does not provide lifecycle events, a built-in event broker,
`listen.foreach` iteration, container/shell execution, or true parallel fork
execution. `FlowReadySet` lets a capable driver identify concurrent branches,
but claiming, limits, and worker fan-out remain host responsibilities.
Temporal or another host can supply those capabilities without becoming a
state-store driver.

See the generated [capability matrix](open-workflow-ctk-matrix.md) for the
scenario-by-scenario result. Every official v1.0.3 scenario is linked to a
named end-to-end runtime test. WorkFlow does not claim external certification:
host-bound capabilities and deployment behavior must still be verified in the
target runtime.

## Version and security boundaries

- The adapter is pinned to OWS DSL 1.0.3. Upgrading the specification is an
  explicit compatibility decision, not an automatic dependency update.
- Definition registration is host-controlled. Never expose document upload or
  activation directly to untrusted clients.
- Expressions are executable definition content. Keep user input in workflow
  data rather than concatenating it into jq expressions.
- Network access exists only when the host registers `OwsHttpExecutor`; apply
  normal SSRF controls and restrict the destinations the PSR-18 client can
  reach.
- TypePHP and elephc native targets compile the core only. They do not include
  this optional adapter unless their build is intentionally extended.

The architectural boundary and delivery history are recorded in
[ADR 0006](adr/0006-open-workflow-specification-adapter.md).
