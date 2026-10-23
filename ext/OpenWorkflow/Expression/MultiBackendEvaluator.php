<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

/**
 * Multi-backend jq evaluator with fallback chain:
 * 1. Shell jq (system binary) - 100% compatibility
 * 2. zest-jq (pure PHP) - good coverage, no deps
 * 3. Built-in minimal evaluator - minimal subset, zero deps
 */
final class MultiBackendEvaluator implements EvaluatorBackend
{
    public function __construct(
        private readonly ShellJqEvaluator $shellJq = new ShellJqEvaluator(),
        private readonly ZestJqEvaluator $zestJq = new ZestJqEvaluator(),
        private readonly ?ExpressionEvaluator $builtinEvaluator = null,
    ) {}

    /**
     * @param mixed $inputData Any JSON-encodable value (object, array, scalar)
     * @return mixed
     */
    public function evaluate(string $expression, mixed $inputData): mixed
    {
        // Try shell jq first (best compatibility)
        if ($this->shellJq->isAvailable()) {
            try {
                return $this->shellJq->evaluate($expression, $inputData);
            } catch (\Throwable $e) {
                // Fall through to next backend
            }
        }

        // Try zest-jq
        try {
            return $this->zestJq->evaluate($expression, $inputData);
        } catch (\Throwable $e) {
            // Fall through to builtin
        }

        // Try builtin evaluator if available
        if ($this->builtinEvaluator !== null) {
            try {
                return $this->builtinEvaluator->evaluate($expression);
            } catch (\Throwable) {
                // Fall through
            }
        }

        throw ExpressionEvaluationException::evaluationError(
            $expression,
            'All jq backends failed (shell jq, zest-jq, builtin)'
        );
    }

    public function analyze(string $expression): array
    {
        // Check shell jq first
        if ($this->shellJq->isAvailable()) {
            return ['supported' => true, 'unsupportedFeatures' => []];
        }

        // Check zest-jq
        $zestResult = $this->zestJq->analyze($expression);
        if ($zestResult['supported']) {
            return $zestResult;
        }

        // Builtin can handle basic subset
        if ($this->builtinEvaluator !== null) {
            return $this->builtinEvaluator->analyze($expression);
        }

        return ['supported' => false, 'unsupportedFeatures' => ['no backend available']];
    }

    public function getActiveBackend(): string
    {
        if ($this->shellJq->isAvailable()) {
            return 'shell-jq';
        }
        return 'zest-jq';
    }

    public function getName(): string
    {
        return 'multi-backend (' . $this->getActiveBackend() . ')';
    }
}