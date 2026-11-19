<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

use InvalidArgumentException;
use UnexpectedValueException;
use Webong\WorkFlow\Jq\Evaluator;
use Webong\WorkFlow\Jq\ZestJqEvaluator;
use Webong\WorkFlow\Rules\RuleSet;

/** Compile the whole bounded document before any predicate can run. */
final readonly class ConditionCompiler
{
    public function __construct(
        private RuleMatcher $rules = new RuleMatcher(),
        private Evaluator $jq = new Evaluator(new ZestJqEvaluator()),
    ) {}

    /** @param array<mixed> $document */
    public function compile(array $document): CompiledCondition
    {
        $remaining = 256;
        return $this->node($document, 0, $remaining);
    }

    /** @param array<mixed> $document */
    private function node(array $document, int $depth, int &$remaining): CompiledCondition
    {
        if ($depth > 32 || --$remaining < 0) { throw new InvalidArgumentException('Condition tree exceeds its limits.'); }
        if ((array_key_exists('version', $document) && $document['version'] !== 'v1')) { throw new InvalidArgumentException('Unsupported condition version.'); }
        $operators = array_values(array_intersect(['all', 'any', 'not'], array_keys($document)));
        if ($operators !== []) {
            if (count($operators) !== 1 || array_diff(array_keys($document), ['version', $operators[0]]) !== []) {
                throw new InvalidArgumentException('A composite condition must contain exactly one operator.');
            }
            $operator = $operators[0];
            $items = $operator === 'not' ? [$document[$operator]] : $document[$operator];
            if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > 64) {
                throw new InvalidArgumentException('Condition children must be a non-empty bounded list.');
            }
            $children = [];
            foreach ($items as $item) {
                if (! is_array($item)) { throw new InvalidArgumentException('Condition child must be a document.'); }
                $children[] = $this->node($item, $depth + 1, $remaining);
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
            if (array_diff(array_keys($document), ['version', 'syntax', 'rules']) !== [] || ! is_array($document['rules'] ?? null)) {
                throw new InvalidArgumentException('Rules condition requires only a rules object.');
            }
            $rules = RuleSet::make($document['rules']);
            return new CompiledCondition(function (mixed $input) use ($rules): ConditionResult {
                if (! is_array($input)) { throw new UnexpectedValueException('Rules input must be an array document.'); }
                return ConditionResult::decided($this->rules->matches($input, $rules));
            });
        }
        if ($syntax === 'jq') {
            $expression = $document['expression'] ?? null;
            if (array_diff(array_keys($document), ['version', 'syntax', 'expression']) !== [] || ! is_string($expression) || trim($expression) === '' || strlen($expression) > 8192) {
                throw new InvalidArgumentException('Jq condition requires a non-empty bounded expression.');
            }
            if (! $this->jq->analyze($expression)['supported']) { throw new InvalidArgumentException('Jq condition cannot be compiled by the configured backend.'); }
            return new CompiledCondition(function (mixed $input) use ($expression): ConditionResult {
                $value = $this->jq->evaluate($expression, $input);
                if (! is_bool($value)) { throw new UnexpectedValueException('Jq condition must return exactly one boolean.'); }
                return ConditionResult::decided($value);
            });
        }
        throw new InvalidArgumentException('Unsupported condition syntax.');
    }
}
