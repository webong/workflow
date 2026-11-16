<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

/**
 * Executes OWS `switch` tasks: evaluates each named case's `when` expression
 * against the switch's task input (first match wins; a case without `when` is
 * the explicit default) and records the routing decision so subsequent chain
 * steps skip or run accordingly.
 *
 * When no case matches and the switch itself does not route (`then: end`/no
 * default), the switch terminates the chain and its task input becomes its
 * output — the workflow output is then the unmodified input (OWS 1.0.3 data
 * flow; the CTK's implicit-default scenario pins this).
 */
final class OwsSwitchStepExecutor implements FlowStepExecutor
{
    public function __construct(
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'switch';
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        $step = $invocation->step;
        $context = $invocation->context;

        if (! $context instanceof OwsWorkflowContext) {
            throw new \RuntimeException(
                "OWS switch task '{$step->id}' requires an OwsWorkflowContext (routing decisions must be shared between steps).",
            );
        }

        $cases = $step->metadata['ows_switch_cases'] ?? [];
        $positions = $step->metadata['ows_chain_positions'] ?? [];
        if (! is_array($cases) || $cases === [] || ! is_array($positions)) {
            throw new \RuntimeException("OWS switch task '{$step->id}' has no routable cases.");
        }

        // Chain input semantics: the most recent executed predecessor's
        // output, or the workflow input. Routing-skipped steps are transparent.
        $rawInput = OwsRouting::resolveChainInput($step, $context);

        $taskInput = $rawInput;
        $inputExpression = $step->metadata['ows_input_expression'] ?? null;
        if (is_string($inputExpression) && $inputExpression !== '') {
            $taskInput = $this->evaluate($inputExpression, $rawInput, $step->id);
        }

        $decision = OwsRouting::decide(
            cases: is_array($cases) ? $cases : [],
            switchThen: is_string($step->metadata['ows_then'] ?? null) ? $step->metadata['ows_then'] : null,
            taskInput: $taskInput,
            positions: $positions,
            switchPosition: is_int($step->metadata['ows_chain_position'] ?? null) ? $step->metadata['ows_chain_position'] : 0,
            evaluator: $this->expressionEvaluator,
        );

        $context->recordRouting($step->id, $decision);

        if ($decision['terminated']) {
            // The workflow output is the unmodified task input.
            $context->recordStepOutput($step->id, $taskInput);
            return StepResult::completed('switch routed to end', [
                'ows_output' => $taskInput,
                'ows_routing' => $decision,
            ], output: StepOutput::from($taskInput));
        }

        return StepResult::completed(
            "switch routed to '{$decision['chosen']}'",
            ['ows_routing' => $decision],
        );
    }

    /**
     * @param mixed $input The jq input (`.`) for the expression
     */
    private function evaluate(string $expression, mixed $input, string $taskId): mixed
    {
        try {
            return $this->expressionEvaluator->evaluateAgainstInput($expression, $input);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "OWS switch task '{$taskId}' failed to evaluate expression '{$expression}': {$e->getMessage()}",
                0,
                $e,
            );
        }
    }
}
