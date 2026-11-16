<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

/**
 * Exception thrown when an expression cannot be evaluated.
 */
final class ExpressionEvaluationException extends \RuntimeException
{
    public static function unsupported(string $expression, string $feature): self
    {
        return new self("Unsupported expression feature '{$feature}' in: {$expression}");
    }

    public static function invalidSyntax(string $expression, string $detail): self
    {
        return new self("Invalid expression syntax: {$detail} in '{$expression}'");
    }

    public static function evaluationError(string $expression, string $detail): self
    {
        return new self("Expression evaluation failed: {$detail} for '{$expression}'");
    }
}