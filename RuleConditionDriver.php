<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel;

use UnexpectedValueException;
use Webong\WorkFlow\Conditions\CompiledCondition;
use Webong\WorkFlow\Conditions\ConditionDefinitionException;
use Webong\WorkFlow\Conditions\ConditionDocument;
use Webong\WorkFlow\Conditions\ConditionResult;
use Webong\WorkFlow\Contracts\ConditionDriver;
use Webong\WorkFlow\Rules\RuleDefinitionException;
use Webong\WorkFlow\Rules\RuleSet;

/** Laravel Validation supplies the rules engine; core never instantiates Illuminate. */
final readonly class RuleConditionDriver implements ConditionDriver
{
    public function __construct(private RuleMatcher $engine = new RuleMatcher()) {}

    public function syntax(): string { return 'rules'; }

    public function compile(array $document): CompiledCondition
    {
        ConditionDocument::properties($document, ['version', 'syntax', 'rules']);
        if (! is_array($document['rules'] ?? null)) {
            throw new ConditionDefinitionException('invalid_condition_rules', 'Rules condition requires a rules object.', '/rules');
        }
        try {
            $rules = RuleSet::make($document['rules']);
        } catch (RuleDefinitionException $error) {
            throw new ConditionDefinitionException('invalid_condition_rule', 'Invalid condition rule definition.', '/rules'.$error->path);
        }
        return new CompiledCondition(function (mixed $input) use ($rules): ConditionResult {
            if (! is_array($input)) { throw new UnexpectedValueException('Rules input must be an array document.'); }
            return ConditionResult::decided($this->engine->matches($input, $rules));
        });
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'syntax' => ['enum' => ['rules']], 'rules' => RuleSet::schema(),
        ], 'required' => ['rules'], 'additionalProperties' => false];
    }
}
