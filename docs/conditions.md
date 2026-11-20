# Conditions and explicit drivers

Core owns condition composition, results, guards, authoring validation and driver registration under `src/Conditions`. It requires PHP only. No predicate engine is selected automatically.

```php
use Webong\WorkFlow\Conditions\ConditionCompiler;
use Webong\WorkFlow\Jq\JqConditionDriver;
use Webong\WorkFlow\Laravel\RuleConditionDriver;

$compiler = new ConditionCompiler([
    new JqConditionDriver(),
    new RuleConditionDriver(),
]);
$condition = $compiler->compile(['all' => [
    ['rules' => ['customer.status' => 'required|in:active']],
    ['syntax' => 'jq', 'expression' => '.amount > 10'],
]]);
$result = $condition->evaluate($data);
if ($result->isError()) { /* report evaluation failure */ }
elseif ($result->matched()) { /* perform the action */ }
```

Install only the engines you need. `webong/workflow-jq` supplies `JqConditionDriver`; its raw jq evaluator still works without core installed. `webong/workflow-laravel` supplies `RuleConditionDriver` and `RuleMatcher`, using Illuminate Validation. An application can implement `Contracts\ConditionDriver` for another engine without changing core.

## Driver interface

A driver exposes `syntax()`, `compile(array $document): CompiledCondition`, and `schema()`. Compilation validates a leaf without evaluating runtime data. Return a compiled predicate whose evaluation produces `ConditionResult`; report configuration errors using `ConditionDefinitionException` with a path relative to the leaf. Core prefixes that path through nested composites. Unexpected compilation failures become safe `condition_driver_failure` diagnostics. Driver schema properties describe the leaf; core supplies its common version property.

Register every driver explicitly. Names must be unique and cannot use `all`, `any` or `not`. Unknown/unregistered syntax yields `condition_driver_unavailable`; installing a package does not enable its driver. A driverless compiler can inspect the composition shape but cannot compile a predicate. Schema output includes only enabled leaf drivers and lists them in `x-condition-syntaxes`.

## Composition and evaluation

Leaves name their driver's `syntax`. The Laravel rules driver also accepts the existing `{version: v1, rules: ...}` form without syntax. Composite documents contain exactly one of `all`, `any` or `not`; all/any take non-empty lists, and not takes one document. Optional version must be v1. The entire definition compiles before evaluation, including branches that will short circuit. Core limits trees to 256 nodes, depth 32 and 64 children per composite. Predicate limits belong to their drivers.

Evaluation is ordered and short circuits. An encountered error propagates through every operator, including not. `matches()` returns false for errors; use `evaluate()` to distinguish errors from non-matches. The jq driver requires exactly one boolean and limits expressions to 8192 bytes. Its default engine is portable Zest; inject an `EvaluatorBackend` to choose another engine explicitly. Expressions are trusted configuration; structural limits do not impose CPU or memory limits on evaluation.

`RuleConditionDriver` accepts a `RuleMatcher`, which can use an application validation factory:

```php
$rules = new RuleConditionDriver(new \Webong\WorkFlow\Laravel\RuleMatcher($validationFactory));
```

The bounded [RuleSet](rules.md) preserves Laravel's missing/null, wildcard, size and coercion semantics while excluding custom/database/regex rules. The Laravel package remains an optional framework adapter with its existing discovery behavior.

## Guards and authoring

Wrap an executor in `GuardedStepExecutor($effects, $compiler)`. A step's `metadata.when` contains the same condition document and sees `{context: ..., input: ...}`. False skips the step, satisfying dependencies under existing core semantics. Errors fail it without retry or executing its effect. Missing when delegates unchanged.

Share that compiler with `ConditionAuthoringValidator($compiler)` and pass it as the `conditions` argument to `FlowDefinitionValidator` or `FlowAuthoringCli`. The existing validation adapter accepts that same binding. Authoring never evaluates predicates. Runtime input types and actual predicate result types remain runtime checks.

For CLI use, create a trusted PHP file returning the drivers:

```php
<?php // condition-drivers.php
return [
    new \Webong\WorkFlow\Jq\JqConditionDriver(),
    new \Webong\WorkFlow\Laravel\RuleConditionDriver(),
];
```

```sh
vendor/bin/workflow validate flow.json --condition-drivers=condition-drivers.php --json
vendor/bin/workflow inspect flow.json --condition-drivers=condition-drivers.php --json
vendor/bin/workflow condition-schema --condition-drivers=condition-drivers.php
```

The [core schema](../src/Conditions/condition.schema.json) describes engine-independent composition. Ask a configured compiler or the CLI for the complete schema including its leaf drivers. Drivers' schemas describe structural shape; compilation additionally checks rule parameters, backend support and global limits. See [authoring](authoring.md#validate-step-conditions) for diagnostic codes and JSON Pointer paths.

## Migrating from the rc.3 subpackages

Conditions and Rules are now core modules, retaining their PHP namespaces. Remove `webong/workflow-conditions` and `webong/workflow-rules` from consumer requirements and require the new core release when available. Core conflicts with those legacy packages to prevent duplicate class ownership. Their published rc.3 tags and index records remain historical artifacts, not current source workspaces.

`RuleMatcher` moved from `Webong\WorkFlow\Conditions` to `Webong\WorkFlow\Laravel`. Replace implicit `new ConditionCompiler()` engines with an explicit driver list and pass the same compiler to authoring and guards. Laravel adapter users need the new adapter release alongside core; old published packages are not rewritten. No Gateway application changes are part of this refactor.
