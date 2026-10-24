<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Executes OWS `emit` tasks: builds the CloudEvent envelope
 * (id, specversion, time, source, type, data) as the task output. Actual
 * event delivery stays host-owned — the executor only produces the envelope,
 * with generated id and time (pinned by the CTK output assertions).
 */
final class OwsEmitExecutor implements FlowStepExecutor
{
    public function __construct(
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'emit';
    }

    public function execute(
        FlowStepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepResult {
        if (! $context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS emit task '{$step->id}' requires an OwsWorkflowContext.");
        }

        $emit = is_array($step->metadata['ows_emit'] ?? null) ? $step->metadata['ows_emit'] : [];
        $rawInput = OwsRouting::resolveChainInput($step, $context);
        $taskInput = $rawInput;
        $inputExpression = $step->metadata['ows_input_expression'] ?? null;
        if (is_string($inputExpression) && $inputExpression !== '') {
            $taskInput = OwsDataTaskPipeline::evaluate($inputExpression, $rawInput, [], $this->expressionEvaluator, $step->id);
        }

        $data = [];
        $emitData = is_array($emit['data'] ?? null) ? $emit['data'] : [];
        foreach ($emitData as $key => $value) {
            $data[(string) $key] = is_string($value)
                ? $this->evaluateOrLiteral($value, $taskInput, $step->id)
                : $value;
        }

        $event = [
            'id' => $this->uuid4(),
            'specversion' => '1.0',
            'time' => gmdate('Y-m-d\TH:i:s\Z'),
            'source' => $this->evaluateOrLiteral(
                is_string($emit['source'] ?? null) ? $emit['source'] : '',
                $taskInput,
                $step->id,
            ),
            'type' => $this->evaluateOrLiteral(
                is_string($emit['type'] ?? null) ? $emit['type'] : '',
                $taskInput,
                $step->id,
            ),
            'data' => $data,
        ];

        $context->recordStepOutput($step->id, $event);

        return StepResult::completed('emitted event', ['ows_output' => $event]);
    }

    /**
     * Emit values are literals unless wrapped as `${ ... }` runtime
     * expressions.
     */
    private function evaluateOrLiteral(string $value, mixed $taskInput, string $taskId): mixed
    {
        $trimmed = trim($value);
        if (preg_match('/^\$\{(.*)\}$/s', $trimmed, $m) !== 1) {
            return $value; // literal
        }
        return OwsDataTaskPipeline::evaluate(trim($m[1]), $taskInput, [], $this->expressionEvaluator, $taskId);
    }

    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}