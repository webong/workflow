<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\OpenWorkflow\ValueObjects\OwsDocument;

/**
 * Executes a data-only task pipeline (set tasks) in-process: per task,
 * `input.from` filters the current data, each `set` value is a literal or a
 * `${ ... }` runtime expression, and the task's output becomes the next
 * task's input. Used by the `for` body (per iteration), `fork` branches, and
 * catch handlers, with runtime-expression arguments (iteration items, error
 * bindings) provided via `owsVARS`.
 */
final class OwsDataTaskPipeline
{
    /**
     * @param list<mixed> $tasks Normalized data tasks
     * @param array<string, mixed> $vars Runtime-expression arguments ($name -> value)
     */
    public static function run(
        array $tasks,
        mixed $input,
        array $vars,
        ExpressionEvaluator $evaluator,
        string $scope = '',
    ): mixed {
        $data = $input;

        foreach (OwsDocument::normalizeTaskItems($tasks) as $index => $task) {
            $set = is_array($task['set'] ?? null) ? $task['set'] : [];
            if ($set === []) {
                continue;
            }

            $taskInput = $data;
            $inputExpression = $task['input']['from'] ?? null;
            if (is_string($inputExpression) && $inputExpression !== '') {
                $taskInput = self::evaluate($inputExpression, $data, $vars, $evaluator, $scope);
            }

            $output = [];
            foreach ($set as $key => $value) {
                $output[(string) $key] = is_string($value)
                    ? self::evaluateOrLiteral($value, $taskInput, $vars, $evaluator, $scope)
                    : $value; // numbers, bools, null, arrays: literal assignment
            }

            $data = $output; // chain: next body task's input = this output
        }

        return $data;
    }

    /**
     * Public entry point: OWS values are literals unless wrapped as
     * `${ ... }` runtime expressions; plain strings assign as-is.
     */
    public static function evaluateOrLiteralValue(string $value, mixed $taskInput, array $vars, ExpressionEvaluator $evaluator, string $scope): mixed
    {
        return self::evaluateOrLiteral($value, $taskInput, $vars, $evaluator, $scope);
    }

    /**
     * OWS set values are literals unless wrapped as `${ ... }` runtime
     * expressions; plain strings assign as-is.
     */
    private static function evaluateOrLiteral(string $value, mixed $taskInput, array $vars, ExpressionEvaluator $evaluator, string $scope): mixed
    {
        $trimmed = trim($value);
        if (preg_match('/^\$\{(.*)\}$/s', $trimmed, $m) !== 1) {
            return $value; // literal
        }
        return self::evaluate(trim($m[1]), $taskInput, $vars, $evaluator, $scope);
    }

    /**
     * @param array<string, mixed> $vars
     */
    public static function evaluate(string $expression, mixed $input, array $vars, ExpressionEvaluator $evaluator, string $scope = ''): mixed
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        // Runtime-expression arguments ($input = the task input, iteration
        // items, catch bindings) are substituted as JSON literals before
        // evaluation, keeping the jq `.` identical to the raw input — an
        // owsVARS merge would corrupt list inputs into objects.
        $vars = $vars + ['input' => $input]; // caller bindings win
        foreach ($vars as $name => $value) {
            $expression = preg_replace(
                '/\$' . preg_quote((string) $name, '/') . '\b/',
                json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $expression,
            ) ?? $expression;
        }

        try {
            return $evaluator->evaluateAgainstInput($expression, $input);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                ($scope !== '' ? "OWS task '{$scope}': " : '') . "failed to evaluate expression '{$expression}': {$e->getMessage()}",
                0,
                $e,
            );
        }
    }
}