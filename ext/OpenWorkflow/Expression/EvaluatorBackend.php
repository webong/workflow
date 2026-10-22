<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

/**
 * Interface for jq expression evaluator backends.
 */
interface EvaluatorBackend
{
    /**
     * Evaluate a jq expression against input data.
     *
     * @param array<string, mixed> $inputData
     * @return mixed
     */
    public function evaluate(string $expression, array $inputData): mixed;

    /**
     * Check if an expression uses only supported features.
     *
     * @return array{supported: bool, unsupportedFeatures: list<string>}
     */
    public function analyze(string $expression): array;

    /**
     * Get the backend name.
     */
    public function getName(): string;
}