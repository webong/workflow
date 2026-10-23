<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Throwable;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowFailureReporter;
use Webong\WorkFlow\Contracts\FlowMutableContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/** Shared execution semantics for inline workers and Temporal Activities. */
final readonly class FlowStepExecution
{
    public function __construct(private FlowFailureReporter $failures = new NullFlowFailureReporter())
    {
    }

    public function execute(FlowStepExecutor $executor, FlowStepDefinition $step, FlowContext $context, StepState $previous, string $flowKey, ?string $runId = null): StepState
    {
        $workflowMeta = [
            'flow_key' => $flowKey,
            'run_id' => $runId,
            'step_id' => $step->id,
            'attempt' => $previous->attempts + 1,
        ];
        // Read-only contexts stay isolated (previous behavior). Mutable
        // contexts get a write-through overlay view so opt-in data flow
        // (e.g. OWS `set` tasks) propagates between steps while still seeing
        // the per-step `_workflow` metadata.
        $executionContext = $this->executionContext($context, $workflowMeta);
        try {
            $result = $executor->execute($step, $executionContext, $previous);
        } catch (Throwable $exception) {
            $correlationId = bin2hex(\random_bytes(16));
            $this->failures->report($exception, $correlationId, $flowKey, $step->id, $runId);
            $result = new StepResult(
                FlowStepStatus::FAILED,
                error: 'Flow step failed.',
                retriable: $step->retryPolicy->enabled ?? $step->retriable,
                metadata: ['error_code' => 'step_execution_failed', 'correlation_id' => $correlationId],
            );
        }

        $attempts = $previous->attempts + 1;
        $retriable = $result->retriable;
        $nextRetryAt = $result->nextRetryAt;

        if ($result->status === FlowStepStatus::FAILED) {
            $retriable = ($retriable ?? $step->retryPolicy->enabled ?? $step->retriable)
                && ($step->retryPolicy?->canRetry($attempts) ?? true);
            $nextRetryAt = $retriable
                ? max($nextRetryAt ?? 0, time() + ($step->retryPolicy->backoffSeconds ?? 0))
                : null;
        }

        $metadata = (array) ($result->deferred ? [...$result->metadata, 'deferred' => true] : $result->metadata);

        return new StepState(
            status: $result->status,
            message: $result->message,
            error: $result->error,
            updatedAt: date(\DATE_ATOM),
            retriable: $retriable,
            attempts: $attempts,
            nextRetryAt: $nextRetryAt,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $workflowMeta */
    private function executionContext(FlowContext $context, array $workflowMeta): FlowContext
    {
        if ($context instanceof FlowMutableContext) {
            return $this->mutableExecutionContext($context, $workflowMeta);
        }

        return new ArrayFlowContext([...$context->all(), '_workflow' => $workflowMeta]);
    }

    // A typed parameter keeps interface dispatch explicit for AOT compilers.
    /** @param array<string, mixed> $workflowMeta */
    private function mutableExecutionContext(FlowMutableContext $context, array $workflowMeta): FlowContext
    {
        return $context->withOverlay(['_workflow' => $workflowMeta]);
    }
}
