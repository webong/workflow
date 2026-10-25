<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;
use Throwable;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowFailureReporter;
use Webong\WorkFlow\Contracts\FlowMutableContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Contracts\FlowStepSchemaValidator;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/** Shared execution semantics for inline workers and Temporal Activities. */
final readonly class FlowStepExecution
{
    public function __construct(
        private FlowFailureReporter $failures = new NullFlowFailureReporter(),
        private readonly ?FlowStepSchemaValidator $schemas = null,
    ) {
    }

    public function execute(FlowStepExecutor $executor, FlowStepInvocation $invocation): StepState
    {
        // Input schemas gate the executor call: a violation fails closed
        // before any side effect, like an unresolved required binding.
        if ($this->schemas !== null) {
            $inputViolations = $this->schemas->validateInput($invocation->step, $invocation->input);
            if ($inputViolations !== []) {
                throw new InvalidArgumentException(sprintf(
                    "Flow step '%s' input violates its schema: %s",
                    $invocation->step->id,
                    implode('; ', $inputViolations),
                ));
            }
        }

        $workflowMeta = [
            'flow_key' => $invocation->flowKey,
            'run_id' => $invocation->runId,
            'step_id' => $invocation->step->id,
            'attempt' => $invocation->attempt,
        ];
        // Read-only contexts stay isolated (previous behavior). Mutable
        // contexts get a write-through overlay view so opt-in data flow
        // (e.g. OWS `set` tasks) propagates between steps while still seeing
        // the per-step `_workflow` metadata.
        $executionContext = $this->executionContext($invocation->context, $workflowMeta);
        $effective = new FlowStepInvocation(
            step: $invocation->step,
            input: $invocation->input,
            context: $executionContext,
            previous: $invocation->previous,
            flowKey: $invocation->flowKey,
            runId: $invocation->runId,
            attempt: $invocation->attempt,
        );
        try {
            $result = $executor->execute($effective);
        } catch (Throwable $exception) {
            $correlationId = bin2hex(\random_bytes(16));
            $this->failures->report($exception, $correlationId, $invocation->flowKey, $invocation->step->id, $invocation->runId);
            $result = new StepResult(
                FlowStepStatus::FAILED,
                error: 'Flow step failed.',
                retriable: $invocation->step->retryPolicy->enabled ?? $invocation->step->retriable,
                metadata: ['error_code' => 'step_execution_failed', 'correlation_id' => $correlationId],
            );
        }

        // Output schemas never block the executor (its effect already
        // happened), but a violating output must not persist as the step's
        // business result: the step fails terminally instead.
        if ($this->schemas !== null && $result->output !== null) {
            $outputViolations = $this->schemas->validateOutput($invocation->step, $result->output);
            if ($outputViolations !== []) {
                $result = new StepResult(
                    FlowStepStatus::FAILED,
                    error: 'Step output violated its schema.',
                    retriable: false,
                    metadata: ['error_code' => 'output_schema_violation', 'schema_violations' => $outputViolations],
                );
            }
        }

        $attempts = $invocation->previous->attempts + 1;
        $retriable = $result->retriable;
        $nextRetryAt = $result->nextRetryAt;

        if ($result->status === FlowStepStatus::FAILED) {
            $retriable = ($retriable ?? $invocation->step->retryPolicy->enabled ?? $invocation->step->retriable)
                && ($invocation->step->retryPolicy?->canRetry($attempts) ?? true);
            $nextRetryAt = $retriable
                ? max($nextRetryAt ?? 0, time() + ($invocation->step->retryPolicy->backoffSeconds ?? 0))
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
            output: $result->output,
            deadlineAt: $result->deferred ? $result->deadlineAt : null,
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
