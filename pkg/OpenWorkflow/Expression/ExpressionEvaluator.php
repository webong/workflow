<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

use Webong\WorkFlow\Contracts\FlowContext;

/**
 * Expression evaluator for OWS expressions.
 *
 * Uses a multi-backend chain with automatic fallback:
 * 1. **Shell jq** (system binary) - 100% jq compatibility when installed
 * 2. **zest-jq** (wikimedia/zest-jq) - pure PHP, good coverage
 * 3. **Builtin minimal evaluator** - zero deps, basic subset
 *
 * Supported grammar (best available backend):
 * - Property access: .field, .field.subfield
 * - Array iteration/projection: .items[] | {id, name}, .items | map(...)
 * - Pipe: expr1 | expr2
 * - Comparison: ==, !=, <, >, <=, >=
 * - Boolean: and, or
 * - Literals: strings, numbers, true, false, null
 * - Variable references: $workflow, $task, $context (mapped to .workflow, .task, .context)
 * - Parentheses: (expr)
 * - Object construction: {key: expr, ...}
 * - Array construction: [expr, ...]
 * - If-then-else: if cond then a else b end
 * - Select/filter: select(...)
 * - Functions: length, keys, add, map, select
 *
 * With shell jq: full jq 1.8+ support including regex, reduce, group_by, modules, etc.
 * With zest-jq: see zest-jq docs (passes 524 upstream jq tests)
 * NOT supported in zest-jq/builtin:
 * - Regex: test(), match(), capture()
 * - not() function (use == false instead)
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
        private ?EvaluatorBackend $backend = null,
    ) {
        $this->backend = $backend ?? new MultiBackendEvaluator();
    }

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

        // Build input data
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

        return $this->evaluateAgainstInput($expression, $inputData);
    }

    /**
     * Evaluate an expression directly against an arbitrary JSON input value,
     * without the OWS variable merging — the input IS the jq `.`.
     * Used for OWS input-filtered data scopes.
     *
     * @param mixed $input Any JSON-encodable value (object, array, scalar)
     * @return mixed
     */
    public function evaluateAgainstInput(string $expression, mixed $input): mixed
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }
        $expression = $this->preprocessVariables($expression);

        // OWS workflow data is a jq object: an empty PHP array must encode as
        // `{}`, not `[]`, or field access on empty data errors.
        if (is_array($input) && $input === []) {
            $input = new \stdClass();
        }

        $result = $this->backend->evaluate($expression, $input);
        return $this->normalizeResult($result);
    }

    /**
     * Normalize results (stdClass -> array, recursively).
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
     * to field access (.workflow, .task, .context).
     */
    private function preprocessVariables(string $expression): string
    {
        // Replace $workflow. with .workflow., $workflow at end with .workflow
        $expression = preg_replace('/\\$workflow\b/', '.workflow', $expression);
        $expression = preg_replace('/\\$task\b/', '.task', $expression);
        $expression = preg_replace('/\\$context\b/', '.context', $expression);

        // Remaining runtime-expression arguments — $input (the task input,
        // bound by executors), iteration items like $color, catch bindings
        // like $err, ... — are provided as an `owsVARS` object merged into
        // the evaluation input.
        $expression = preg_replace('/\\$([a-zA-Z_][a-zA-Z0-9_]*)\b/', '.owsVARS.$1', $expression);

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

        // Pre-process variables before analysis
        $expression = $this->preprocessVariables($expression);

        // Check with multi-backend
        return $this->backend->analyze($expression);
    }

    /**
     * Get the name of the active backend.
     */
    public function getActiveBackend(): string
    {
        if ($this->backend instanceof MultiBackendEvaluator) {
            return $this->backend->getActiveBackend();
        }
        return get_class($this->backend);
    }
}