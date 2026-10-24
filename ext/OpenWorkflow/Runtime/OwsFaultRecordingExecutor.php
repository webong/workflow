<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Wraps a host executor so faults raised inside a try scope are recorded on
 * the OwsWorkflowContext and routed to the matching catch handler. Compose
 * host executors through this decorator when a workflow uses try/catch:
 *
 * ```php
 * $executors = array_map(
 *     fn (FlowStepExecutor $e) => new OwsFaultRecordingExecutor($e),
 *     $hostExecutors,
 * );
 * ```
 */
final class OwsFaultRecordingExecutor implements FlowStepExecutor
{
    public function __construct(
        private readonly FlowStepExecutor $inner,
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return $this->inner->supports($step);
    }

    public function execute(
        FlowStepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepResult {
        try {
            return $this->inner->execute($step, $context, $previous);
        } catch (OwsFaultException $fault) {
            $this->record($step, $context, $fault->toFault(
                is_string($step->metadata['ows_instance'] ?? null) ? $step->metadata['ows_instance'] : $step->id,
            ));
            throw $fault;
        } catch (\Throwable $e) {
            $this->record($step, $context, [
                'status' => 500,
                'type' => 'https://serverlessworkflow.io/errors/types/runtime',
                'title' => $e->getMessage() !== '' ? $e->getMessage() : get_class($e),
                'instance' => is_string($step->metadata['ows_instance'] ?? null) ? $step->metadata['ows_instance'] : $step->id,
            ]);
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $fault
     */
    private function record(FlowStepDefinition $step, FlowContext $context, array $fault): void
    {
        if (! $context instanceof OwsWorkflowContext) {
            return;
        }
        $scope = is_array($step->metadata['ows_try_scope'] ?? null)
            ? ($step->metadata['ows_try_scope']['id'] ?? '')
            : '';
        $context->recordFault(is_string($scope) ? $scope : '', $fault);
    }
}