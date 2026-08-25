<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Temporal;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Framework-neutral activity handler used by the Temporal SDK adapter.
 *
 * @param iterable<FlowStepExecutor> $executors
 */
final class TemporalFlowActivityHandler
{
    /** @var list<FlowStepExecutor> */
    private readonly array $executors;

    /** @param iterable<FlowStepExecutor> $executors */
    public function __construct(iterable $executors)
    {
        $executorList = [];

        foreach ($executors as $executor) {
            if (! $executor instanceof FlowStepExecutor) {
                throw new InvalidArgumentException('Temporal flow activities require FlowStepExecutor instances.');
            }

            $executorList[] = $executor;
        }

        $this->executors = $executorList;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array
    {
        $definitionData = $input['definition'] ?? null;
        $stepId = $input['step_id'] ?? null;
        $previousData = $input['previous'] ?? [];
        $contextData = $input['context'] ?? [];

        if (! is_array($definitionData) || ! is_string($stepId) || $stepId === '') {
            throw new InvalidArgumentException('Temporal flow activity input requires a definition and step ID.');
        }

        $definition = FlowDefinition::fromArray($definitionData);
        $step = $definition->step($stepId);

        if (! $step instanceof FlowStepDefinition) {
            throw new InvalidArgumentException("Unknown WorkFlow step '{$stepId}'.");
        }

        $previous = is_array($previousData) ? StepState::fromArray($previousData) : new StepState();
        $context = new ArrayFlowContext(is_array($contextData) ? $this->stringKeyed($contextData) : []);
        $executor = $this->executorFor($step);

        try {
            $result = $executor->execute($step, $context, $previous);
        } catch (Throwable $exception) {
            $result = StepResult::failed(
                error: $exception->getMessage() !== '' ? $exception->getMessage() : 'Flow step failed.',
                retriable: $step->retriable,
            );
        }

        $attempts = $previous->attempts + 1;

        if ($result->status === FlowStepStatus::FAILED && $step->retryPolicy !== null) {
            $canRetry = $step->retryPolicy->canRetry($attempts);
            $result = new StepResult(
                status: $result->status,
                message: $result->message,
                error: $result->error,
                retriable: $canRetry,
                attempts: $attempts,
                nextRetryAt: $canRetry ? time() + $step->retryPolicy->backoffSeconds : null,
                metadata: $result->metadata,
                deferred: $result->deferred,
            );
        }

        $state = $result->toState();

        return (new StepState(
            status: $state->status,
            message: $state->message,
            error: $state->error,
            updatedAt: $state->updatedAt,
            retriable: $state->retriable,
            attempts: $attempts,
            nextRetryAt: $state->nextRetryAt,
            metadata: $state->metadata,
        ))->toArray();
    }

    private function executorFor(FlowStepDefinition $step): FlowStepExecutor
    {
        foreach ($this->executors as $executor) {
            if ($executor->supports($step)) {
                return $executor;
            }
        }

        throw new RuntimeException("No executor registered for flow step '{$step->id}'.");
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
