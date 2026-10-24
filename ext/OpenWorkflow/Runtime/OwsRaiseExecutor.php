<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Executes OWS `raise` tasks: faults the workflow with the error payload.
 * The fault is recorded on the OwsWorkflowContext (scoped to an enclosing
 * try when present) so a catch handler can claim it; the step itself fails
 * non-retriably, which surfaces as a faulted run when the error is uncaught.
 */
final class OwsRaiseExecutor implements FlowStepExecutor
{
    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'raise';
    }

    public function execute(
        FlowStepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepResult {
        $raise = is_array($step->metadata['ows_raise'] ?? null) ? $step->metadata['ows_raise'] : [];
        $fault = [
            'status' => $raise['status'] ?? null,
            'type' => $raise['type'] ?? null,
            'title' => $raise['title'] ?? null,
            'instance' => $raise['instance'] ?? (is_string($step->metadata['ows_instance'] ?? null) ? $step->metadata['ows_instance'] : $step->id),
        ];

        if ($context instanceof OwsWorkflowContext) {
            $scope = is_array($step->metadata['ows_try_scope'] ?? null)
                ? ($step->metadata['ows_try_scope']['id'] ?? '')
                : '';
            $context->recordFault(is_string($scope) ? $scope : '', $fault);
        }

        return StepResult::failed(
            error: is_string($fault['title']) ? $fault['title'] : 'Raised error',
            retriable: false,
            metadata: ['ows_error' => $fault],
        );
    }
}