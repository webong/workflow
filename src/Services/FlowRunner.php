<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use RuntimeException;
use Throwable;
use Zorvia\WebFlow\Contracts\FlowContext;
use Zorvia\WebFlow\Contracts\FlowStepExecutor;
use Zorvia\WebFlow\Enums\StepStatus;
use Zorvia\WebFlow\ValueObjects\FlowDefinition;
use Zorvia\WebFlow\ValueObjects\FlowState;
use Zorvia\WebFlow\ValueObjects\StepState;

final class FlowRunner
{
    public function __construct(private readonly FlowEvaluator $evaluator = new FlowEvaluator())
    {
    }

    /**
     * Executes each supported step once in definition order. The host can
     * queue this operation or persist the returned state through its adapter.
     *
     * @param iterable<FlowStepExecutor> $executors
     */
    public function run(
        FlowDefinition $definition,
        FlowState $state,
        FlowContext $context,
        iterable $executors,
    ): FlowState {
        $executors = is_array($executors) ? $executors : iterator_to_array($executors, false);

        foreach ($definition->steps as $step) {
            $previous = $state->steps[$step->id] ?? new StepState();

            if (in_array($previous->status, [StepStatus::COMPLETED, StepStatus::SKIPPED], true)) {
                continue;
            }

            if ($step->dependsOn !== [] && ! $this->dependenciesCompleted($step->dependsOn, $state)) {
                continue;
            }

            $executor = null;
            foreach ($executors as $candidate) {
                if ($candidate instanceof FlowStepExecutor && $candidate->supports($step)) {
                    $executor = $candidate;
                    break;
                }
            }

            if (! $executor instanceof FlowStepExecutor) {
                throw new RuntimeException("No executor registered for flow step '{$step->id}'.");
            }

            $stepState = $this->execute($executor, $step, $context, $previous);
            $state = $state->withStep($step->id, new StepState(
                status: $stepState->status,
                message: $stepState->message,
                error: $stepState->error,
                updatedAt: $stepState->updatedAt,
                retriable: $stepState->retriable,
                attempts: $previous->attempts + 1,
                nextRetryAt: $stepState->nextRetryAt,
                metadata: $stepState->metadata,
            ));
        }

        return $this->evaluator->evaluate($definition, $state);
    }

    /** @param list<string> $dependencies */
    private function dependenciesCompleted(array $dependencies, FlowState $state): bool
    {
        foreach ($dependencies as $dependency) {
            $step = $state->steps[$dependency] ?? null;

            if ($step === null || ! in_array($step->status, [StepStatus::COMPLETED, StepStatus::SKIPPED], true)) {
                return false;
            }
        }

        return true;
    }

    private function execute(
        FlowStepExecutor $executor,
        \Zorvia\WebFlow\ValueObjects\StepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepState {
        try {
            return $executor->execute($step, $context, $previous)->toState();
        } catch (Throwable $exception) {
            return (new \Zorvia\WebFlow\ValueObjects\StepResult(
                status: StepStatus::FAILED,
                error: $exception->getMessage() !== '' ? $exception->getMessage() : 'Flow step failed.',
                retriable: $step->retriable,
            ))->toState();
        }
    }
}
