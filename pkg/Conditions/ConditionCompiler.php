<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

use Throwable;
use UnexpectedValueException;
use Webong\WorkFlow\Jq\Evaluator;
use Webong\WorkFlow\Jq\ZestJqEvaluator;
use Webong\WorkFlow\Rules\RuleDefinitionException;
use Webong\WorkFlow\Rules\RuleSet;

/** Compile the whole bounded document before any predicate can run. */
final readonly class ConditionCompiler
{
    public const MAX_DEPTH = 32;
    public const MAX_NODES = 256;
    public const MAX_CHILDREN = 64;
    public const MAX_EXPRESSION_BYTES = 8192;

    public function __construct(
        private RuleMatcher $rules = new RuleMatcher(),
        private Evaluator $jq = new Evaluator(new ZestJqEvaluator()),
    ) {}

    /** @param array<mixed> $document */
    public function compile(array $document): CompiledCondition
    {
        $remaining = self::MAX_NODES;
        return $this->node($document, 0, $remaining, '');
    }

    /** @param array<mixed> $document */
    private function node(array $document, int $depth, int &$remaining, string $path): CompiledCondition
    {
        if ($depth > self::MAX_DEPTH || --$remaining < 0) {
            throw new ConditionDefinitionException('condition_limit_exceeded', 'Condition tree exceeds its limits.', $path);
        }
        if (array_key_exists('version', $document) && $document['version'] !== 'v1') {
            throw new ConditionDefinitionException('invalid_condition_version', 'Unsupported condition version.', $path.'/version');
        }
        $operators = array_values(array_intersect(['all', 'any', 'not'], array_keys($document)));
        if ($operators !== []) {
            if (count($operators) !== 1) {
                throw new ConditionDefinitionException('invalid_condition_operator', 'A condition must contain exactly one operator.', $path);
            }
            $operator = $operators[0];
            $this->properties($document, ['version', $operator], $path);
            $items = $operator === 'not' ? [$document[$operator]] : $document[$operator];
            if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX_CHILDREN) {
                throw new ConditionDefinitionException('invalid_condition_children', 'Condition children must be a non-empty bounded list.', $path.'/'.$operator);
            }
            $children = [];
            foreach ($items as $index => $item) {
                $childPath = $path.'/'.$operator.($operator === 'not' ? '' : '/'.$index);
                if (! is_array($item)) {
                    throw new ConditionDefinitionException('condition_not_object', 'Condition child must be a document.', $childPath);
                }
                $children[] = $this->node($item, $depth + 1, $remaining, $childPath);
            }
            return new CompiledCondition(static function (mixed $input) use ($operator, $children): ConditionResult {
                foreach ($children as $child) {
                    $result = $child->evaluate($input);
                    if ($result->isError()) { return $result; }
                    if ($operator === 'not') { return ConditionResult::decided(! $result->matched()); }
                    if ($operator === 'all' && ! $result->matched()) { return $result; }
                    if ($operator === 'any' && $result->matched()) { return $result; }
                }
                return ConditionResult::decided($operator === 'all');
            });
        }
        $syntax = array_key_exists('syntax', $document) ? $document['syntax'] : (array_key_exists('rules', $document) ? 'rules' : null);
        if ($syntax === 'rules') {
            $this->properties($document, ['version', 'syntax', 'rules'], $path);
            if (! is_array($document['rules'] ?? null)) {
                throw new ConditionDefinitionException('invalid_condition_rules', 'Rules condition requires a rules object.', $path.'/rules');
            }
            try {
                $rules = RuleSet::make($document['rules']);
            } catch (RuleDefinitionException $error) {
                throw new ConditionDefinitionException('invalid_condition_rule', 'Invalid condition rule definition.', $path.'/rules'.$error->path);
            }
            return new CompiledCondition(function (mixed $input) use ($rules): ConditionResult {
                if (! is_array($input)) { throw new UnexpectedValueException('Rules input must be an array document.'); }
                return ConditionResult::decided($this->rules->matches($input, $rules));
            });
        }
        if ($syntax === 'jq') {
            $this->properties($document, ['version', 'syntax', 'expression'], $path);
            $expression = $document['expression'] ?? null;
            if (! is_string($expression) || trim($expression) === '' || strlen($expression) > self::MAX_EXPRESSION_BYTES) {
                throw new ConditionDefinitionException('invalid_condition_expression', 'Jq condition requires a non-empty bounded expression.', $path.'/expression');
            }
            try {
                $supported = $this->jq->analyze($expression)['supported'];
            } catch (Throwable) {
                throw new ConditionDefinitionException('condition_backend_unavailable', 'The configured jq backend could not analyze this expression.', $path.'/expression');
            }
            if (! $supported) {
                throw new ConditionDefinitionException('unsupported_condition_expression', 'Jq condition cannot be compiled by the configured backend.', $path.'/expression');
            }
            return new CompiledCondition(function (mixed $input) use ($expression): ConditionResult {
                $value = $this->jq->evaluate($expression, $input);
                if (! is_bool($value)) { throw new UnexpectedValueException('Jq condition must return exactly one boolean.'); }
                return ConditionResult::decided($value);
            });
        }
        throw new ConditionDefinitionException('invalid_condition_syntax', 'Unsupported condition syntax.', $path.'/syntax');
    }

    /**
     * @param array<mixed> $document
     * @param list<string> $allowed
     */
    private function properties(array $document, array $allowed, string $path): void
    {
        foreach (array_keys($document) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new ConditionDefinitionException('unknown_condition_property', 'Unsupported condition property.', $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key));
            }
        }
    }
}
