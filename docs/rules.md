# Core rule definitions

`Webong\WorkFlow\Rules\RuleSet` lives in `src/Rules` and ships with core. Parsing and serialization require only PHP; an engine is needed only to evaluate the rules.

```php
use Webong\WorkFlow\Rules\RuleSet;

$rules = RuleSet::make(['customer.status' => 'required|in:active']);
$document = $rules->toArray(); // version: v1, rules: normalized string lists
$restored = RuleSet::fromArray($document);
```

Field paths preserve case and accept dotted segments and wildcards. Applications own field restrictions and input normalization. Definitions accept at most 64 fields, 16 rules per field and 1024 bytes per rule. Unknown rules, custom rule objects, database rules and regular expressions are rejected. Invalid definitions throw `InvalidArgumentException`; `RuleDefinitionException` adds a JSON Pointer relative to the rules mapping.

Supported rules: accepted, array, bail, between, boolean, declined, ends_with, filled, in, integer, list, max, min, not_in, nullable, numeric, present, required, size, starts_with, string. Pipe strings and explicit lists are supported; use a list when a parameter contains a pipe.

The optional Illuminate adapter (`webong/workflow-illuminate`) supplies `RuleConditionDriver` and `RuleMatcher`. Core never loads Illuminate to parse a rule document. See [conditions](conditions.md) for explicit driver registration.
