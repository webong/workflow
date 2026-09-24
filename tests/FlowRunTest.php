<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowFailureReporter;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\FlowEvaluator;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\Services\FlowStepExecution;
use Webong\WorkFlow\Services\InMemoryFlowStateStore;
use Webong\WorkFlow\Services\RunScopedFlowStateStore;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRetryPolicy;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

final class FlowRunTest extends TestCase
{
    public function test_runs_are_isolated_and_legacy_state_is_preserved(): void
    {
        $storage = new InMemoryFlowStateStore();
        $legacy = new FlowState(FlowStatus::COMPLETED);
        $storage->put('setup', $legacy);
        $definition = $this->definition();
        $first = new RunScopedFlowStateStore($storage, 'first');
        $second = new RunScopedFlowStateStore($storage, 'second');
        $first->put('setup', (new FlowRun('first', $definition))->initialState());
        $second->put('setup', (new FlowRun('second', $definition))->initialState());
        $first->mutate('setup', static fn (?FlowState $state): FlowState => $state->withMetadata(['changed' => true]));

        self::assertArrayNotHasKey('changed', $second->get('setup')->metadata);
        self::assertSame($legacy, $storage->get('setup'));
        $first->forget('setup');
        self::assertNull($first->get('setup'));
        self::assertSame('second', $second->get('setup')->run->id);
    }

    public function test_run_snapshot_survives_serialization_and_evaluation(): void
    {
        $run = new FlowRun('one', $this->definition());
        $state = FlowState::fromArray($run->initialState()->withMetadata(['x' => 1])->toArray());
        $evaluated = (new FlowEvaluator())->evaluate($run->definition, $state);

        self::assertSame($run->toArray(), $evaluated->run->toArray());
        self::assertNull(FlowState::fromArray(['status' => 'pending'])->run);
    }

    public function test_store_rejects_another_run_without_overwriting(): void
    {
        $store = new RunScopedFlowStateStore(new InMemoryFlowStateStore(), 'one');
        $original = (new FlowRun('one', $this->definition()))->initialState();
        $store->put('setup', $original);
        try {
            $store->put('setup', (new FlowRun('two', $this->definition()))->initialState());
            self::fail('Wrong run accepted');
        } catch (InvalidArgumentException) {
            self::assertSame($original, $store->get('setup'));
        }
    }

    public function test_store_keeps_the_driver_fixed_and_cancelled_runs_terminal(): void
    {
        $store = new RunScopedFlowStateStore(new InMemoryFlowStateStore(), 'one');
        $state = (new FlowRun('one', $this->definition()))->initialState()->withMetadata(['execution_driver' => 'queue']);
        $store->put('setup', $state);
        try {
            $store->put('setup', $state->withMetadata(['execution_driver' => 'temporal']));
            self::fail('Driver switched');
        } catch (InvalidArgumentException) {
            self::assertSame($state, $store->get('setup'));
        }
        $store->put('setup', (new FlowStateTransition())->cancel($state));
        $this->expectException(InvalidArgumentException::class);
        $store->put('setup', $state);
    }

    public function test_definition_changes_are_rejected_even_when_version_is_unchanged(): void
    {
        $state = (new FlowRun('one', $this->definition()))->initialState();
        $this->expectException(InvalidArgumentException::class);
        (new FlowRunner())->run(new FlowDefinition('setup', [new FlowStepDefinition('other', 'Other')]), $state, new ArrayFlowContext(), []);
    }

    public function test_pinned_metadata_is_detached_from_mutable_authoring_references(): void
    {
        $provider = 'first';
        $definition = new FlowDefinition('setup', metadata: ['provider' => &$provider]);
        $run = new FlowRun('one', $definition);
        $provider = 'second';
        self::assertSame('first', $run->definition->metadata['provider']);
        $this->expectException(InvalidArgumentException::class);
        $run->assertDefinition($definition);
    }

    public function test_resuming_does_not_repeat_deferred_work_and_executes_forward_dependencies(): void
    {
        $definition = new FlowDefinition('setup', [
            new FlowStepDefinition('verify', 'Verify', dependsOn: ['authorize']),
            new FlowStepDefinition('authorize', 'Authorize'),
        ]);
        $calls = [];
        $executor = $this->executor(function (FlowStepDefinition $step) use (&$calls): StepResult {
            $calls[] = $step->id;
            return $step->id === 'authorize' ? StepResult::deferred() : StepResult::completed();
        });
        $runner = new FlowRunner();
        $state = $runner->run($definition, (new FlowRun('one', $definition))->initialState(), new ArrayFlowContext(), [$executor]);
        $state = $runner->run($definition, $state, new ArrayFlowContext(), [$executor]);
        self::assertSame(['authorize'], $calls);
        $state = (new FlowStateTransition())->complete($definition, $state, new FlowDeferredCompletion(
            'setup', 'authorize', 'event-1', StepResult::completed(), 'one', 1,
        ));
        $state = $runner->run($definition, $state, new ArrayFlowContext(), [$executor]);
        self::assertSame(['authorize', 'verify'], $calls);
        self::assertSame(FlowStatus::COMPLETED, $state->status);
    }

    public function test_a_pending_optional_step_keeps_the_run_open(): void
    {
        $definition = new FlowDefinition('optional', [new FlowStepDefinition('later', 'Later', critical: false)]);
        self::assertSame(FlowStatus::RUNNING, (new FlowEvaluator())->evaluate($definition)->status);
    }

    public function test_non_retriable_results_override_the_retry_policy(): void
    {
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize', retryPolicy: new FlowRetryPolicy())]);
        $calls = 0;
        $executor = $this->executor(function () use (&$calls): StepResult {
            $calls++;
            return StepResult::failed('Permission denied', retriable: false);
        });
        $runner = new FlowRunner();
        $state = $runner->run($definition, (new FlowRun('one', $definition))->initialState(), new ArrayFlowContext(), [$executor]);
        $state = $runner->run($definition, $state, new ArrayFlowContext(), [$executor]);
        self::assertSame(1, $calls);
        self::assertSame(FlowStatus::BLOCKED, $state->status);
    }

    public function test_exceptions_are_private_and_obey_retry_backoff(): void
    {
        $reporter = new class implements FlowFailureReporter {
            public array $reported = [];
            public function report(Throwable $exception, string $correlationId, string $flowKey, string $stepId, ?string $runId): void
            {
                $this->reported = [$exception->getMessage(), $correlationId, $runId];
            }
        };
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize', retryPolicy: new FlowRetryPolicy(backoffSeconds: 60))]);
        $calls = 0;
        $executor = $this->executor(function () use (&$calls): StepResult {
            $calls++;
            throw new RuntimeException('secret-provider-token');
        });
        $runner = new FlowRunner(execution: new FlowStepExecution($reporter));
        $state = $runner->run($definition, (new FlowRun('one', $definition))->initialState(), new ArrayFlowContext(), [$executor]);
        $runner->run($definition, $state, new ArrayFlowContext(), [$executor]);
        self::assertSame(1, $calls);
        self::assertStringNotContainsString('secret-provider-token', json_encode($state->toArray()));
        self::assertSame('secret-provider-token', $reporter->reported[0]);
        self::assertSame($state->steps['authorize']->metadata['correlation_id'], $reporter->reported[1]);
        self::assertGreaterThan(time(), $state->steps['authorize']->nextRetryAt);
    }

    public function test_callbacks_reject_wrong_run_and_stale_attempt_before_deduplication(): void
    {
        $state = $this->waitingState();
        $transition = new FlowStateTransition();
        foreach ([['other', 1], ['one', 2], [null, null]] as [$runId, $attempt]) {
            try {
                $transition->complete($this->definition(), $state, new FlowDeferredCompletion('setup', 'authorize', 'event', StepResult::completed(), $runId, $attempt));
                self::fail('Wrong correlation accepted');
            } catch (InvalidArgumentException) {
                self::assertSame(FlowStepStatus::PENDING, $state->steps['authorize']->status);
            }
        }
    }

    public function test_failed_callbacks_obey_the_same_retry_backoff(): void
    {
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize', retryPolicy: new FlowRetryPolicy(backoffSeconds: 3600))]);
        $state = (new FlowRun('one', $definition))->initialState()->withStep('authorize', new StepState(attempts: 1, metadata: ['deferred' => true]));
        $now = time();
        $state = (new FlowStateTransition(clock: static fn (): int => $now))->complete($definition, $state,
            new FlowDeferredCompletion('setup', 'authorize', 'failed', StepResult::failed('Try later', true), 'one', 1));
        self::assertSame($now + 3600, $state->steps['authorize']->nextRetryAt);
        $calls = 0;
        (new FlowRunner())->run($definition, $state, new ArrayFlowContext(), [$this->executor(function () use (&$calls): StepResult {
            $calls++;
            return StepResult::completed();
        })]);
        self::assertSame(0, $calls);
    }

    public function test_equivalent_json_objects_and_numbers_do_not_break_identity_or_deduplication(): void
    {
        $definition = new FlowDefinition('setup', metadata: ['z' => 1.0, 'a' => ['b' => 2, 'a' => 1]]);
        $state = (new FlowRun('one', $definition))->initialState();
        $decoded = FlowState::fromArray(json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $decoded->run->assertDefinition($definition);
        $transition = new FlowStateTransition();
        $completion = new FlowDeferredCompletion('setup', 'authorize', 'same', StepResult::completed(metadata: ['z' => 1.0, 'a' => 2]), 'one', 1);
        $state = $transition->complete($this->definition(), $this->waitingState(), $completion);
        $equivalent = new FlowDeferredCompletion('setup', 'authorize', 'same', StepResult::completed(metadata: ['a' => 2, 'z' => 1]), 'one', 1);
        self::assertSame($state, $transition->complete($this->definition(), $state, $equivalent));
    }

    public function test_callback_duplicates_are_idempotent_but_conflicting_payloads_are_rejected(): void
    {
        $transition = new FlowStateTransition(timestamp: static fn (): string => 'recorded-time');
        $completion = new FlowDeferredCompletion('setup', 'authorize', 'event', StepResult::completed('OK'), 'one', 1);
        $state = $transition->complete($this->definition(), $this->waitingState(), $completion);
        self::assertSame('recorded-time', $state->steps['authorize']->updatedAt);
        self::assertSame($state, $transition->complete($this->definition(), $state, $completion));
        $this->expectException(InvalidArgumentException::class);
        $transition->complete($this->definition(), $state, new FlowDeferredCompletion('setup', 'authorize', 'event', StepResult::failed('Changed'), 'one', 1));
    }

    public function test_event_ids_are_scoped_to_steps(): void
    {
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('a', 'A'), new FlowStepDefinition('b', 'B')]);
        $waiting = new StepState(attempts: 1, metadata: ['deferred' => true]);
        $state = (new FlowRun('one', $definition))->initialState()->withStep('a', $waiting)->withStep('b', $waiting);
        foreach (['a', 'b'] as $step) {
            $state = (new FlowStateTransition())->complete($definition, $state, new FlowDeferredCompletion('setup', $step, 'same-provider-event', StepResult::completed(), 'one', 1));
        }
        self::assertSame(FlowStatus::COMPLETED, $state->status);
    }

    public function test_cancelled_runs_do_not_execute_or_accept_callbacks(): void
    {
        $transition = new FlowStateTransition();
        $state = $transition->cancel($this->waitingState());
        self::assertSame($state, (new FlowRunner())->run($this->definition(), $state, new ArrayFlowContext(), []));
        $this->expectException(InvalidArgumentException::class);
        $transition->complete($this->definition(), $state, new FlowDeferredCompletion('setup', 'authorize', 'late', StepResult::completed(), 'one', 1));
    }

    public function test_replaying_a_tracked_run_requires_a_new_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FlowStateTransition())->reset($this->waitingState());
    }

    public function test_complete_deferred_uses_the_saved_definition_for_a_tracked_run(): void
    {
        $state = (new FlowStateTransition())->completeDeferred($this->waitingState(),
            new FlowDeferredCompletion('setup', 'authorize', 'done', StepResult::completed(), 'one', 1));
        self::assertSame(FlowStatus::COMPLETED, $state->status);
    }

    private function definition(): FlowDefinition
    {
        return new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')]);
    }

    private function waitingState(): FlowState
    {
        return (new FlowRun('one', $this->definition()))->initialState()->withStep('authorize', new StepState(attempts: 1, metadata: ['deferred' => true]));
    }

    private function executor(Closure $execute): FlowStepExecutor
    {
        return new class($execute) implements FlowStepExecutor {
            public function __construct(private Closure $execute) {}
            public function supports(FlowStepDefinition $step): bool { return true; }
            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                return ($this->execute)($step, $context, $previous);
            }
        };
    }
}
