<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Jq;

use Throwable;
use UnexpectedValueException;
use Webong\WorkFlow\Conditions\CompiledCondition;
use Webong\WorkFlow\Conditions\ConditionDefinitionException;
use Webong\WorkFlow\Conditions\ConditionDocument;
use Webong\WorkFlow\Conditions\ConditionResult;
use Webong\WorkFlow\Contracts\ConditionDriver;

/** Optional integration with WorkFlow core; raw Evaluator has no core dependency. */
final readonly class JqConditionDriver implements ConditionDriver
{
    public const MAX_EXPRESSION_BYTES = 8192;

    public function __construct(private EvaluatorBackend $engine = new ZestJqEvaluator()) {}

    public function syntax(): string { return 'jq'; }

    public function compile(array $document): CompiledCondition
    {
        ConditionDocument::properties($document, ['version', 'syntax', 'expression']);
        $expression = $document['expression'] ?? null;
        if (! is_string($expression) || trim($expression) === '' || strlen($expression) > self::MAX_EXPRESSION_BYTES) {
            throw new ConditionDefinitionException('invalid_condition_expression', 'Jq condition requires a non-empty bounded expression.', '/expression');
        }
        try {
            $supported = $this->engine->analyze($expression)['supported'];
        } catch (Throwable) {
            throw new ConditionDefinitionException('condition_backend_unavailable', 'The configured jq backend could not analyze this expression.', '/expression');
        }
        if (! $supported) {
            throw new ConditionDefinitionException('unsupported_condition_expression', 'Jq condition cannot be compiled by the configured backend.', '/expression');
        }
        return new CompiledCondition(function (mixed $input) use ($expression): ConditionResult {
            $value = $this->engine->evaluate($expression, $input);
            if (! is_bool($value)) { throw new UnexpectedValueException('Jq condition must return exactly one boolean.'); }
            return ConditionResult::decided($value);
        });
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'syntax' => ['enum' => ['jq']],
            'expression' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_EXPRESSION_BYTES],
        ], 'required' => ['syntax', 'expression'], 'additionalProperties' => false];
    }
}
