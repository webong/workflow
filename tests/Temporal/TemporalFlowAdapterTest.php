<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Temporal;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Temporal\TemporalFlowActivityHandler;
use Webong\WorkFlow\Temporal\TemporalFlowCompletion;
use Webong\WorkFlow\Temporal\TemporalFlowExecutionDriver;
use Webong\WorkFlow\Temporal\TemporalFlowIdentity;
use Webong\WorkFlow\Temporal\TemporalFlowInput;
use Webong\WorkFlow\Temporal\TemporalCompletionInbox;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowRetryPolicy;
use Webong\WorkFlow\ValueObjects\StepState;
use Webong\WorkFlow\ValueObjects\StepResult;

final class TemporalFlowAdapterTest extends TestCase
{
    public function test_identity_is_stable_and_encodes_subject_components(): void
    {
        $identity = new TemporalFlowIdentity(
            subject: new FlowStateSubject('channel type', 'channel/1'),
            flowKey: 'channel setup',
        );

        self::assertSame('work-flow:channel%20type:channel%2F1:channel%20setup', $identity->workflowId());
    }

    public function test_workflow_input_round_trips_definition_state_and_context(): void
    {
        $input = new TemporalFlowInput(
            definition: new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')]),
            state: new FlowState(FlowStatus::RUNNING),
            context: ['channel_id' => 'channel-1'],
        );

        $roundTrip = TemporalFlowInput::fromArray($input->toArray());

        self::assertSame($input->definition->toArray(), $roundTrip->definition->toArray());
        self::assertSame($input->state->toArray(), $roundTrip->state->toArray());
        self::assertSame(['channel_id' => 'channel-1'], $roundTrip->context);
    }

    public function test_deferred_completion_round_trips_as_signal_payload(): void
    {
        $completion = new TemporalFlowCompletion(new FlowDeferredCompletion(
            flowKey: 'setup',
            stepId: 'authorize',
            idempotencyKey: 'provider-event-1',
            result: StepResult::completed('Authorized.'),
        ));

        $roundTrip = TemporalFlowCompletion::fromArray($completion->toArray());

        self::assertSame('setup', $roundTrip->completion->flowKey);
        self::assertSame('authorize', $roundTrip->completion->stepId);
        self::assertSame('provider-event-1', $roundTrip->completion->idempotencyKey);
        self::assertSame(FlowStepStatus::COMPLETED, $roundTrip->completion->result->status);
        self::assertSame('Authorized.', $roundTrip->completion->result->message);
    }

    public function test_invalid_completion_payload_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TemporalFlowCompletion::fromArray(['flow_key' => 'setup']);
    }

    public function test_run_identity_and_callback_attempt_survive_transport(): void
    {
        $subject = new FlowStateSubject('channel', '42');
        $first = new TemporalFlowIdentity($subject, 'setup', 'one');
        $second = new TemporalFlowIdentity($subject, 'setup', 'two');
        self::assertNotSame($first->workflowId(), $second->workflowId());
        $completion = new TemporalFlowCompletion(new FlowDeferredCompletion('setup', 'authorize', 'event', StepResult::completed(), 'one', 2));
        $roundTrip = TemporalFlowCompletion::fromArray($completion->toArray());
        self::assertSame('one', $roundTrip->completion->runId);
        self::assertSame(2, $roundTrip->completion->attempt);

        $definition = new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')], version: 3);
        $input = new TemporalFlowInput($definition, (new FlowRun('one', $definition))->initialState());
        self::assertSame($input->state->run->toArray(), TemporalFlowInput::fromArray($input->toArray())->state->run->toArray());
    }

    public function test_signal_inbox_preserves_progress_before_terminal_completion(): void
    {
        $inbox = new TemporalCompletionInbox();
        $progress = new FlowDeferredCompletion('setup', 'authorize', 'progress', StepResult::pending('Almost'), 'one', 1);
        $finished = new FlowDeferredCompletion('setup', 'authorize', 'done', StepResult::completed(), 'one', 1);
        $inbox->push($progress);
        $inbox->push($finished);
        self::assertTrue($inbox->has('authorize'));
        self::assertSame($progress, $inbox->shift('authorize'));
        self::assertSame($finished, $inbox->shift('authorize'));
        self::assertFalse($inbox->has('authorize'));
        self::assertNull($inbox->shift('authorize'));
    }

    public function test_late_duplicates_cannot_starve_another_steps_callback(): void
    {
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('a', 'A'), new FlowStepDefinition('b', 'B')]);
        $waiting = new StepState(attempts: 1, metadata: ['deferred' => true]);
        $state = (new FlowRun('one', $definition))->initialState()->withStep('a', $waiting)->withStep('b', $waiting);
        $completed = new FlowDeferredCompletion('setup', 'a', 'event-a', StepResult::completed(), 'one', 1);
        $state = (new \Webong\WorkFlow\Services\FlowStateTransition())->complete($definition, $state, $completed);
        // Exercise signal admission without a Temporal runtime or activity stub.
        $reflection = new \ReflectionClass(\Webong\WorkFlow\Temporal\TemporalFlowWorkflow::class);
        $workflow = $reflection->newInstanceWithoutConstructor();
        $inbox = new TemporalCompletionInbox();
        foreach (['state' => $state, 'definition' => $definition, 'completions' => $inbox] as $property => $value) {
            $reflection->getProperty($property)->setValue($workflow, $value);
        }
        for ($i = 0; $i < 1100; $i++) {
            $workflow->complete((new TemporalFlowCompletion($completed))->toArray());
        }
        $next = new FlowDeferredCompletion('setup', 'b', 'event-b', StepResult::completed(), 'one', 1);
        $workflow->complete((new TemporalFlowCompletion($next))->toArray());
        self::assertFalse($inbox->has('a'));
        self::assertSame('event-b', $inbox->shift('b')->idempotencyKey);
        self::assertSame(0, $workflow->snapshot()['rejected_completions']);
    }

    public function test_activity_handler_delegates_to_a_host_executor(): void
    {
        $executor = new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return $step->id === 'authorize';
            }

            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                return StepResult::completed(metadata: ['source' => $context->get('source')]);
            }
        };

        $result = (new TemporalFlowActivityHandler([$executor]))->execute([
            'definition' => (new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')]))->toArray(),
            'step_id' => 'authorize',
            'context' => ['source' => 'provider'],
            'previous' => (new StepState())->toArray(),
        ]);

        self::assertSame(FlowStepStatus::COMPLETED->value, $result['status']);
        self::assertSame(1, $result['attempts']);
        self::assertSame('provider', $result['metadata']['source']);
    }

    public function test_activity_handler_preserves_deferred_results(): void
    {
        $executor = new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                return StepResult::deferred('Waiting for callback.');
            }
        };

        $result = (new TemporalFlowActivityHandler([$executor]))->execute([
            'definition' => (new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')]))->toArray(),
            'step_id' => 'authorize',
            'previous' => (new StepState())->toArray(),
        ]);

        self::assertSame(FlowStepStatus::PENDING->value, $result['status']);
        self::assertTrue($result['metadata']['deferred']);
    }

    public function test_activity_handler_applies_retry_policy_to_failed_results(): void
    {
        $executor = new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                throw new RuntimeException('Provider unavailable.');
            }
        };

        $result = (new TemporalFlowActivityHandler([$executor]))->execute([
            'definition' => (new FlowDefinition('setup', [new FlowStepDefinition(
                'authorize',
                'Authorize',
                retryPolicy: new FlowRetryPolicy(maxAttempts: 2),
            )]))->toArray(),
            'step_id' => 'authorize',
            'previous' => (new StepState())->toArray(),
        ]);

        self::assertSame(FlowStepStatus::FAILED->value, $result['status']);
        self::assertTrue($result['retriable']);
        self::assertSame(1, $result['attempts']);
        self::assertStringNotContainsString('Provider unavailable.', json_encode($result));
        self::assertSame('step_execution_failed', $result['metadata']['error_code']);
    }

    public function test_temporal_execution_driver_serializes_the_workflow_start_request(): void
    {
        $captured = null;
        $driver = new TemporalFlowExecutionDriver(
            start: static function (string $workflowId, array $input) use (&$captured): string {
                $captured = [$workflowId, $input];

                return 'temporal-run-1';
            },
        );

        $receipt = $driver->dispatch(new \Webong\WorkFlow\ValueObjects\FlowExecutionRequest(
            definition: new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')]),
            state: new FlowState(FlowStatus::PENDING),
            context: ['source' => 'api'],
            driver: 'temporal',
            subject: new FlowStateSubject('channel', 'channel-1'),
        ));

        self::assertSame('temporal', $receipt->driver);
        self::assertSame('temporal-run-1', $receipt->executionId);
        self::assertSame('work-flow:channel:channel-1:setup', $captured[0]);
        self::assertSame(['source' => 'api'], $captured[1]['context']);
    }
}
