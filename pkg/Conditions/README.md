# WorkFlow Conditions

`webong/workflow-conditions` compiles reusable rules and jq predicates, boolean compositions and optional workflow guards. It uses Illuminate Validation directly, without a Laravel application. Raw jq remains independently available in `webong/workflow-jq`.

```php
use Webong\WorkFlow\Conditions\ConditionCompiler;

$condition = (new ConditionCompiler())->compile(['all' => [
    ['rules' => ['customer.status' => 'required|in:active']],
    ['syntax' => 'jq', 'expression' => '.amount > 10'],
]]);
$result = $condition->evaluate($data);
if ($result->isError()) { /* report an evaluation failure */ }
elseif ($result->matched()) { /* perform the action */ }
```

Leaves support `rules` (pipe strings or lists) or `syntax: jq` plus `expression`. Existing `{version: v1, rules: ...}` documents work unchanged. Composite documents contain exactly one of `all`, `any` or `not`; all/any take non-empty lists and not takes one document. Optional version must be v1. The whole definition is validated before evaluation, including branches that will short circuit. Limits are 256 nodes, depth 32, 64 children per composite and 8192 bytes per jq expression.

Evaluation is ordered and short circuits. An encountered error propagates through every operator, including not. `matches()` returns false for errors; use `evaluate()` to distinguish errors from ordinary non-matches. jq predicates must produce exactly one boolean; truthiness and multiple outputs are rejected. The default jq backend is the portable Zest implementation; inject another Evaluator explicitly when needed. Expressions are trusted configuration: these structural limits do not impose CPU or memory limits on jq execution.

`RuleMatcher` accepts an optional Illuminate validation factory, preserving the host's validator configuration. Rules use Laravel's missing/null, wildcard, size and coercion semantics. The bounded RuleSet excludes custom/database/regex rules.

To opt a core executor into conditional steps, wrap it in `GuardedStepExecutor`. A step's existing `metadata.when` contains the same condition document and sees `{context: ..., input: ...}`. False skips the step, satisfying dependencies under the core runner's existing skip behavior; errors fail it without retry and without executing its effect. Missing when delegates unchanged. Guarding is opt-in and does not change the core serialized schema.

Hosts remain responsible for normalization, authorization, subscription ownership and delivery. Conditions contain no Gateway-specific fields or behavior.

## Authoring

`ConditionAuthoringValidator` implements the optional core `FlowConditionValidator` contract. Bind it as `new FlowDefinitionValidator(conditions: new ConditionAuthoringValidator($compiler))` to validate guards before a run starts. Share the same compiler with runtime guards. Validation never evaluates a predicate: actual jq return types and data-dependent errors remain runtime checks. Diagnostics contain stable codes and escaped JSON Pointers, with one condition error per step.

The installed `workflow` binary discovers this integration when available. `workflow validate`, `workflow inspect`, and the existing validation API use the same compiler. `workflow condition-schema` exports [the structural JSON Schema](condition.schema.json); the authoritative compiler also checks rule parameters, backend support and whole-tree limits. See [the authoring guide](../../docs/authoring.md#validate-step-conditions).

This new authoring integration requires core `^0.1.0-rc.4`; source changes do not alter already-published rc.3 packages. `ConditionDefinitionException` remains an `InvalidArgumentException` and adds a relative `path` and `diagnosticCode` for authoring tools.
