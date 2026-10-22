<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

use Webong\WorkFlow\Contracts\FlowContext;

/**
 * Expression evaluator for OWS expressions.
 *
 * Uses zest-jq (wikimedia/zest-jq) as the primary backend for jq expression evaluation,
 * with support for OWS-specific variable references ($workflow, $task, $context).
 *
 * Supported grammar (via zest-jq):
 * - Property access: .field, .field.subfield
 * - Array iteration/projection: .items[] | {id, name}, .items | map(...)
 * - Pipe: expr1 | expr2
 * - Comparison: ==, !=, <, >, <=, >=
 * - Boolean: and, or
 * - Literals: strings, numbers, true, false, null
 * - Variable references: $variableName (zest-jq native)
 * - Parentheses: (expr)
 * - Object construction: {key: expr, ...}
 * - Array construction: [expr, ...]
 * - If-then-else: if cond then a else b end
 * - Select/filter: select(...)
 * - Functions: length, keys, add, map, select
 *
 * NOT supported (throws ExpressionEvaluationException):
 * - Regex: test(), match(), capture() (not implemented in zest-jq)
 * - not() function (use != false instead)
 * - reduce, group_by, flatten, etc.
 * - Module system (import, include, module)
 * - Alternative operators: //, ?
 */
final class ExpressionEvaluator
{
    /**
     * @param array<string, mixed> $workflowData
     * @param array<string, mixed> $taskData
     */
    public function __construct(
        private readonly array $workflowData = [],
        private readonly array $taskData = [],
        private readonly ?FlowContext $context = null,
        private readonly ZestJqEvaluator $zestJq = new ZestJqEvaluator(),
    ) {}

    /**
     * Evaluate an expression against the current data context.
     *
     * @return mixed
     */
    public function evaluate(string $expression): mixed
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }

        // Pre-process OWS variable references to zest-jq field access
        // $workflow -> .workflow, $task -> .task, $context -> .context
        $expression = $this->preprocessVariables($expression);

        // Build input data for zest-jq
        // Merge workflow data at root for bare property access (.status)
        // Add workflow, task, context as explicit fields
        $inputData = array_merge(
            $this->workflowData,
            [
                'workflow' => $this->workflowData,
                'task' => $this->taskData,
                'context' => $this->context?->all() ?? [],
            ]
        );

        $result = $this->zestJq->evaluate($expression, $inputData);
        return $this->normalizeResult($result);
    }

    /**
     * Normalize zest-jq results (stdClass -> array, recursively).
     */
    private function normalizeResult(mixed $value): mixed
    {
        if (is_object($value)) {
            return $this->normalizeResult(json_decode(json_encode($value), true));
        }
        if (is_array($value)) {
            return array_map([$this, 'normalizeResult'], $value);
        }
        return $value;
    }

    /**
     * Convert OWS variable references ($workflow, $task, $context)
     * to zest-jq field access (.workflow, .task, .context).
     */
    private function preprocessVariables(string $expression): string
    {
        // Replace $workflow. with .workflow., $workflow at end with .workflow
        $expression = preg_replace('/\\$workflow\b/', '.workflow', $expression);
        $expression = preg_replace('/\\$task\b/', '.task', $expression);
        $expression = preg_replace('/\\$context\b/', '.context', $expression);

        return $expression;
    }

    /**
     * Check if an expression uses only supported features.
     *
     * @return array{supported: bool, unsupportedFeatures: list<string>}
     */
    public function analyze(string $expression): array
    {
        $expression = trim($expression);
        if ($expression === '') {
            return ['supported' => true, 'unsupportedFeatures' => []];
        }

        // Check with zest-jq
        $result = $this->zestJq->analyze($expression);

        // Additionally check for OWS-specific variable references
        // that zest-jq might not recognize as valid
        if ($result['supported']) {
            // Our variable references ($workflow, $task, $context) are just
            // regular variables in zest-jq, so they're fine
        }

        return $result;
    }
}