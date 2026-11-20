# WorkFlow Illuminate adapter

`webong/workflow-illuminate` provides the rules predicate engine using Illuminate Validation and Translation. It does not require `laravel/framework`, the WorkFlow Laravel adapter, database/cache implementations or Spatie packages. It has no service provider, application discovery, config or migrations. Core condition composition and rule definitions remain PHP-only.

```php
use Webong\WorkFlow\Conditions\ConditionCompiler;
use Webong\WorkFlow\Illuminate\RuleConditionDriver;
use Webong\WorkFlow\Illuminate\RuleMatcher;

$driver = new RuleConditionDriver();
// Or preserve an application's configured Illuminate validation factory:
$driver = new RuleConditionDriver(new RuleMatcher($validationFactory));
$compiler = new ConditionCompiler([$driver]);
$condition = $compiler->compile([
    'syntax' => 'rules',
    'rules' => ['payload.type' => 'required|string|in:order.paid'],
]);
$result = $condition->evaluate($normalizedDocument);
```

The default matcher creates an Illuminate validation factory with an in-memory translation loader. No application boot is required. The driver uses core's bounded `RuleSet`; database, custom and regex rules remain excluded. Missing/null handling, wildcards and numeric coercion use Illuminate's semantics.

Share the compiler with `ConditionAuthoringValidator` and `GuardedStepExecutor`. See [core conditions](../../docs/conditions.md) for authoring, composition and explicit CLI driver registration. Installing the adapter does not enable it automatically.

This adapter needs core `^0.1.0-rc.4`. `RuleMatcher` and `RuleConditionDriver` now live in `Webong\WorkFlow\Illuminate`, independently of the Laravel app integration. The new package needs publication before external consumers can adopt it; existing rc.3 package records are unchanged.
