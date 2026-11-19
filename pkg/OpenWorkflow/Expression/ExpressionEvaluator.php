<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Expression;

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Jq\EvaluatorBackend;
use Webong\WorkFlow\Jq\Evaluator;
use Webong\WorkFlow\Jq\MultiBackendEvaluator;

/**
 * OWS scopes and variable preprocessing over the standalone jq evaluator.
 * Raw query execution, backend selection and JSON normalization belong to
 * webong/workflow-jq; this facade adds workflow/task/context conventions only.
 */
final class ExpressionEvaluator
{
    private readonly Evaluator $jq;

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
        $this->jq = new Evaluator($this->backend);
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

        return $this->jq->evaluate($expression, $input);
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