# Standalone jq evaluation

`webong/workflow-jq` evaluates jq against ordinary JSON-compatible PHP data.
It requires PHP 8.3+ and `wikimedia/zest-jq`, with no dependency on WorkFlow core,
OpenWorkflow, Laravel or Gateway. Native `jq` is optional.

```php
use Webong\WorkFlow\Jq\Evaluator;
use Webong\WorkFlow\Jq\ZestJqEvaluator;

$jq = new Evaluator(); // native jq when available, otherwise Zest
$id = $jq->evaluate('.order.id', ['order' => ['id' => 42]]);
$matches = $jq->evaluate('.amount >= 100', ['amount' => 125]);

$portable = new Evaluator(new ZestJqEvaluator()); // explicitly pure PHP
```

The input is exactly jq's `.`. PHP lists stay JSON arrays; `new stdClass()` is an
empty JSON object. No workflow fields are injected, empty input is not converted
to an object, and `$workflow`/other jq variables or string literals are not
rewritten. OpenWorkflow's context/variable conventions remain in its own facade.

`evaluate()` is a convenience API: one result becomes that PHP value, multiple
results become a list, and zero results become `null`. JSON objects are exposed
as associative arrays. Thus zero outputs and an explicit `null` share a return
value; a jq array and a same-valued result stream may also look alike. Use a jq
array constructor such as `[.items[]]` when the query should return one array.
`ExpressionEvaluationException` reports evaluation failures; boolean results
remain booleans rather than being coerced into a match decision.

`MultiBackendEvaluator` selects native `jq` once by availability, otherwise
Zest. Runtime/query errors are propagated, not retried against another dialect.
Use explicit backends for consistent deployments. Native jq uses the installed
binary's language; Zest implements a subset. `analyze()` compiles without executing the query, checking syntax/backend
support; it does not guarantee success on every input. `ExpressionParser`/`ExpressionAnalyzer` expose the existing
limited structural grammar; they are not a complete native jq parser.

Install from an independently built package ZIP as described in
[package distribution](https://github.com/webong/workflow/blob/main/docs/packages.md).
The archive is built alongside the other packages by `tools/package-composer.php`.
This new package has not been published by this source extraction. OpenWorkflow
requires it as an ordinary Composer dependency and adds only OWS-specific scopes.
Generic classes now use `Webong\WorkFlow\Jq`; the OWS-specific
`OpenWorkflow\Expression\ExpressionEvaluator` facade keeps its existing namespace.
