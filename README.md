# WorkFlow Rules

`webong/workflow-rules` provides immutable, JSON-serializable Laravel-shaped rule definitions. It requires PHP only and can be used without WorkFlow, jq or Laravel.

```php
use Webong\WorkFlow\Rules\RuleSet;

$rules = RuleSet::make(['customer.status' => 'required|in:active']);
$document = $rules->toArray(); // version: v1, rules: normalized string lists
$restored = RuleSet::fromArray($document);
```

Field paths preserve case and accept dotted segments and wildcards. Hosts own their field restrictions and input normalization. Definitions accept at most 64 fields, 16 rules per field and 1024 bytes per rule. Unknown rules, custom rule objects, database rules and regular expressions are rejected. Invalid definitions throw `InvalidArgumentException`.

Supported rules: accepted, array, bail, between, boolean, declined, ends_with, filled, in, integer, list, max, min, not_in, nullable, numeric, present, required, size, starts_with, string. Pipe strings and explicit lists are supported; use a list when a parameter contains a pipe.

Evaluation is supplied by `webong/workflow-conditions`; this package only parses and validates definitions.
