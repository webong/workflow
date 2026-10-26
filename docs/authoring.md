# Author a flow definition

WorkFlow validates definitions as data. A definition is checked against the
topology rules, the host's executors, the capabilities the host grants, and
the optional schemas published by operation descriptors — without starting a
run, touching a store, or invoking an executor.

There is exactly one rule set. It lives in
`Webong\WorkFlow\Services\FlowDefinitionValidator` and is used by:

- the `workflow` CLI (`validate`, `inspect`, `operations`);
- the `flow.validate` JSON-RPC method;
- host tooling and tests.

Generated Node, Python, and Go contracts carry types only. They never
reimplement validation: a client sends the document to the server and renders
the diagnostics that come back.

## Validate from the CLI

`bin/workflow` ships with the package. In a checkout, run it through PHP; once
installed as a Composer dependency it is available as `vendor/bin/workflow`.

```bash
php bin/workflow validate flow.json
php bin/workflow validate - < flow.json          # read standard input
php bin/workflow validate flow.json --json
php bin/workflow validate flow.json \
    --executors=executors.php \
    --capabilities=approval,billing
```

Text output lists each diagnostic as `severity /path code: message`, then a
summary line:

```text
error /steps/1/depends_on/0 unknown_dependency: Step 'notify' depends on unknown step 'approve2'.
Invalid: 1 error(s), 0 warning(s).
```

`--json` prints the same object `flow.validate` returns:

```json
{
  "valid": false,
  "diagnostics": [
    {
      "code": "unknown_dependency",
      "message": "Step 'notify' depends on unknown step 'approve2'.",
      "severity": "error",
      "path": "/steps/1/depends_on/0",
      "step_id": "notify",
      "details": {}
    }
  ]
}
```

Exit codes are stable for scripting:

| Code | Meaning |
| --- | --- |
| `0` | The definition is valid. |
| `1` | The definition is invalid or cannot be parsed. |
| `2` | Usage or input error (missing file, bad JSON, unreadable executor file). |

### Give the CLI your host's context

The validator knows nothing about your executors unless you give them to it.

```bash
# executors.php returns an array of FlowStepExecutor instances.
php bin/workflow validate flow.json --executors=executors.php

# Granted capabilities turn capability requirements into pass/fail checks.
php bin/workflow validate flow.json --capabilities=approval,billing
```

Without `--executors`, operation, input-schema, and output-schema checks are
skipped and the report carries an `operations_unchecked` warning. Without
`--capabilities`, capability requirements are skipped and the report carries
`capabilities_unchecked`. Both are warnings: the document can still be valid,
but the report does not pretend those surfaces were checked.

An executor file is ordinary PHP that returns a list:

```php
<?php

use Webong\WorkFlow\Contracts\FlowOperationDirectory;
use Webong\WorkFlow\Contracts\FlowOperationProvider;
use Webong\WorkFlow\Contracts\FlowStepExecutor;

return [
    new class implements FlowStepExecutor, FlowOperationProvider, FlowOperationDirectory {
        // supports(), execute(), operation(), operations()
    },
];
```

### Inspect a definition

```bash
php bin/workflow inspect flow.json --executors=executors.php
```

`inspect` prints the flow key, version, steps in execution order, each declared
binding, and the operation descriptor behind each step, then the validation
diagnostics. `--json` returns one object with `definition`, `operations`,
`valid`, and `diagnostics`.

### List what the host publishes

```bash
php bin/workflow operations --executors=executors.php
php bin/workflow operations --definition=flow.json --executors=executors.php
```

With no `--definition`, `operations` lists the
`FlowOperationDirectory::operations()` catalog. With `--definition`, it lists
the descriptor each step would run, keyed by step id. This is the discovery
step an author uses before writing a definition.

## Validate over JSON-RPC

`flow.validate` is a pure method: the definition travels in the request and
need not be registered, and no store or executor is invoked.

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "flow.validate",
  "params": {
    "definition": { "key": "orders", "version": 1, "steps": [] }
  }
}
```

- `definition` is required and must be an object. Otherwise the call fails
  with `-32602 Invalid definition`.
- Capability grants come from the trusted host's `FlowRpcApplication`
  configuration. RPC callers cannot supply or override them. When the host
  binds no grant list, capability requirements produce
  `capabilities_unchecked` warnings.
- The host's bound operation providers supply operation, input, and output
  checks. They never gain execution rights by being bound.

The result is the serialized `FlowValidationReport`:

```json
{
  "valid": true,
  "diagnostics": []
}
```

## Understand the diagnostics

Each diagnostic has a stable `code`, a human-readable `message`, a `severity`
(`error`, `warning`, or `info`), and a `path`. The path is an RFC 6901 JSON
Pointer into the submitted document, so `~` and `/` inside a property or
binding name are escaped (`a/b~c` appears as `a~1b~0c`). A report is valid when
it contains no `error`-severity diagnostic. Warnings never block a run.

| Code | Severity | Meaning |
| --- | --- | --- |
| `definition_not_object` | error | The document is not a JSON object. |
| `missing_key` | error | `key` is missing or not a non-empty string. |
| `invalid_version` | error | `version` is not a positive integer. |
| `invalid_steps` | error | `steps` is not a list. |
| `invalid_definition` | error | The document cannot be constructed as a runtime `FlowDefinition`; the message is the runtime reason. |
| `no_steps` | warning | `steps` is empty. |
| `step_not_object` | error | A step entry is not an object. |
| `missing_step_id` | error | A step has no non-empty string `id`. |
| `duplicate_step_id` | error | Two steps share an id. The first declaration is canonical. |
| `invalid_step` | error | A step cannot be parsed; the message is the parser reason. |
| `invalid_dependencies` | error | `depends_on` is not a list. |
| `invalid_dependency` | error | A `depends_on` entry is not a non-empty string. |
| `self_dependency` | error | A step depends on itself. |
| `unknown_dependency` | error | A step depends on an id the definition does not declare. |
| `dependency_cycle` | error | Steps form a cycle; `details.cycle` lists the cycle. |
| `invalid_input_bindings` | error | `input` is not an object. |
| `invalid_binding_name` | error | A binding name is not a non-empty string. |
| `invalid_binding` | error | A binding entry is not an object. |
| `invalid_binding_source` | error | `source` is not `context` or `output`. |
| `invalid_pointer` | error | `pointer` is neither empty nor a valid JSON Pointer. |
| `invalid_binding_required` | warning | `required` is not a boolean; it is read as one. |
| `binding_source_missing_step` | error | An output binding names no source step. |
| `binding_source_step_unknown` | error | An output binding names an undeclared step. |
| `binding_source_step_not_dependency` | error | An output binding reads a step that is not a declared dependency. |
| `binding_context_has_step` | error | A context binding names a step. |
| `unsupported_step` | error | No bound executor's `supports()` accepts the step. |
| `invalid_capability` | warning | A capability declaration is not a list of non-empty strings. |
| `missing_capability` | error | A required capability (definition or operation) is not granted. |
| `capabilities_unchecked` | warning | Capability requirements exist but the host supplied no grants. |
| `operations_unchecked` | warning | No executors were bound, so operation and schema checks were skipped. |
| `missing_required_input` | error | An operation requires an input the step does not bind. |
| `invalid_schema` | warning | An operation's schema has an unusable `required` field. |
| `unknown_output_property` | warning | An output binding reads a property the source operation does not declare. |

## Use the generated contracts

`composer types` regenerates all checked-in contracts from
`tools/contracts.php`:

| File | Language |
| --- | --- |
| `types/work-flow.d.ts` | TypeScript |
| `types/work_flow.py` | Python 3.11+ |
| `types/work_flow.go` | Go |

In Go, `StepState.Output` is `json.RawMessage`: `nil` means the `output` key
was absent, while `json.RawMessage("null")` means the step explicitly produced
JSON null. This preserves the same output-presence contract as PHP, TypeScript,
and Python.

The authoring types are `FlowDefinition`, `FlowStepDefinition`,
`FlowInputBinding`, `FlowOperation`, `FlowDiagnostic`,
`FlowValidationReport`, and `FlowValidateParams`. They are transport shapes:
their only job is to make the JSON-RPC request and response typed. Validation
stays on the server.

```ts
const response = await call('flow.validate', {
  definition: { key: 'orders', version: 1, steps: [] },
} satisfies FlowValidateParams);
```

## A complete catalog-backed example

[`examples/catalog-flow.php`](../examples/catalog-flow.php) runs the whole
authoring loop without a framework:

1. A host executor implements `FlowStepExecutor`,
   `FlowOperationProvider`, and `FlowOperationDirectory`.
2. The example prints the operations the host publishes.
3. It constructs a definition from those descriptors, binding `notify` to
   `approve`'s structured output.
4. It validates the document through `flow.validate` using capabilities bound
   by the trusted host.
5. It starts the flow, observes the deferred `approve` step, completes it, and
   resumes to `notify`.

```bash
php examples/catalog-flow.php
```

```text
Operations: catalog.approve (Approve an order), catalog.notify (Notify the customer)
Validation: valid (0 errors, 0 warnings)
After start: running; approve: pending; notify: pending
After completion: running; approve: completed
After resume: completed; notify: completed
approve output: {"order_id":"order-42","decision":"approved"}
notify output: {"order_id":"order-42","decision":"approved"}
```

The example is exercised by `tests/Examples/CatalogFlowExampleTest.php`, so it
cannot drift from the API it documents.

## What validation does not do

- It never starts, resumes, completes, or cancels a run.
- It never reads or writes a state store.
- It never invokes an executor.
- It does not grant a capability or permission; grants are the host's input.
- It does not interpret JSON Schema beyond the optional `required` and
  `properties` fields on operation descriptors the host publishes.

Those boundaries are what let the same module back a CLI, an RPC method, and
generated clients without turning any of them into an execution surface.
