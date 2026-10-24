<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;

/**
 * OWS `switch` routing: case evaluation and skip decisions, shared by the
 * runtime executor and the CTK static-assertion simulation.
 *
 * Decision shape (recorded per switch step in the OwsWorkflowContext):
 * - terminated: no branch ran (no case matched, no default) — every chain
 *   step after the switch skips and the switch's task input becomes its
 *   output, so the workflow output is the unmodified input.
 * - chosen: the `then` target of the matching (or default) case.
 * - skip: chain step ids that must skip (other case targets, steps between
 *   the switch and the chosen target, and — when the chosen target ends the
 *   chain — everything after it).
 */
final class OwsRouting
{
    /**
     * Evaluate switch cases against the switch's task input and compute the
     * routing decision.
     *
     * @param list<array{name: string, when: ?string, then: ?string, end_after: bool}> $cases
     * @param array<string, int> $positions chain positions (step id => index)
     * @param array<string, mixed>|null $inputData workflow input for static simulation
     * @return array{terminated: bool, chosen: ?string, skip: list<string>, switch_position: int}
     */
    public static function decide(
        array $cases,
        ?string $switchThen,
        mixed $taskInput,
        array $positions,
        int $switchPosition,
        ?ExpressionEvaluator $evaluator = null,
    ): array {
        $evaluator ??= new ExpressionEvaluator();
        $chosen = null;
        $endAfter = false;

        foreach ($cases as $case) {
            if ($case['when'] !== null) {
                try {
                    $matches = self::truthy($evaluator->evaluateAgainstInput($case['when'], $taskInput));
                } catch (\Throwable) {
                    $matches = false; // a failing condition does not match
                }
                if (! $matches) {
                    continue;
                }
            }
            // No `when` = explicit default: matches here.
            $chosen = $case['then'];
            $endAfter = $case['end_after'];
            break;
        }

        if ($chosen === null || $chosen === 'end' || $chosen === 'exit') {
            // No case matched (or routed straight to end): the switch
            // terminates without a branch.
            return [
                'terminated' => true,
                'chosen' => null,
                'skip' => [],
                'switch_position' => $switchPosition,
            ];
        }

        $skip = [];
        $chosenPosition = $positions[$chosen] ?? null;

        // Other case targets never run under this routing decision.
        foreach ($cases as $case) {
            if ($case['then'] !== null && $case['then'] !== $chosen && isset($positions[$case['then']])) {
                $skip[] = $case['then'];
            }
        }

        if ($chosenPosition !== null) {
            // Steps between the switch and the chosen target are bypassed by
            // the routing jump.
            foreach ($positions as $id => $position) {
                if ($position > $switchPosition && $position < $chosenPosition) {
                    $skip[] = $id;
                }
            }
            // When the chosen target ends the chain, everything after it skips.
            if ($endAfter) {
                foreach ($positions as $id => $position) {
                    if ($position > $chosenPosition) {
                        $skip[] = $id;
                    }
                }
            }
        }

        return [
            'terminated' => false,
            'chosen' => $chosen,
            'skip' => array_values(array_unique($skip)),
            'switch_position' => $switchPosition,
        ];
    }

    /**
     * Whether a step must skip because a switch decision excluded it.
     */
    public static function shouldSkip(FlowStepDefinition $step, OwsWorkflowContext $context): bool
    {
        foreach ($context->routingDecisions() as $decision) {
            if (in_array($step->id, $decision['skip'], true)) {
                return true;
            }
            $position = $step->metadata['ows_chain_position'] ?? null;
            if ($decision['terminated'] === true
                && is_int($position)
                && $position > $decision['switch_position']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a task's raw input per the OWS data chain: the most recent
     * executed predecessor's output. Steps skipped by routing are transparent;
     * unexecuted steps (including routing switches, which record no output)
     * are walked past. When no predecessor has run, the workflow input is the
     * raw input.
     */
    public static function resolveChainInput(FlowStepDefinition $step, OwsWorkflowContext $context): mixed
    {
        $previousById = is_array($step->metadata['ows_previous_by_id'] ?? null)
            ? $step->metadata['ows_previous_by_id']
            : [];

        $skipped = [];
        foreach ($context->routingDecisions() as $decision) {
            foreach ($decision['skip'] as $id) {
                $skipped[$id] = true;
            }
        }

        $predecessor = is_string($step->metadata['ows_previous'] ?? null) && $step->metadata['ows_previous'] !== ''
            ? $step->metadata['ows_previous']
            : null;

        $visited = [];
        while (is_string($predecessor) && ! isset($visited[$predecessor])) {
            $visited[$predecessor] = true;
            if (isset($skipped[$predecessor]) || ! $context->hasOutput($predecessor)) {
                // Transparent: routing-skipped or not-yet-executed steps do
                // not contribute data.
                $predecessor = is_string($previousById[$predecessor] ?? null) ? $previousById[$predecessor] : null;
                continue;
            }
            return $context->stepOutput($predecessor);
        }

        return $context->workflowInput();
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_null($value)) {
            return false;
        }
        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }
        if (is_string($value)) {
            return $value !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }
        return true;
    }
}