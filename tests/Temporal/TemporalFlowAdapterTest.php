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
use Webong\WorkFlow\Temporal\TemporalFlowIdentity;
use Webong\WorkFlow\Temporal\TemporalFlowInput;
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
    }
}
