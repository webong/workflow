<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webong\WorkFlow\Contracts\FlowActionHandler;
use Webong\WorkFlow\Contracts\FlowActionRegistry;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\FlowEvaluator;
use Webong\WorkFlow\Services\FlowActionDispatcher;
use Webong\WorkFlow\Services\FlowPresentationFactory;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\Services\FlowStateMigrationRunner;
use Webong\WorkFlow\Services\InMemoryFlowStateStore;
use Webong\WorkFlow\Services\DefaultFlowStateSerializer;
use Webong\WorkFlow\Services\CollectingFlowEventSink;
use Webong\WorkFlow\Contracts\FlowStateMigrator;
use Webong\WorkFlow\ValueObjects\FlowAction;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;
use Webong\WorkFlow\ValueObjects\FlowActionContext;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowRetryPolicy;

final class FlowEvaluatorTest extends TestCase
{
    public function test_a_retriable_critical_failure_needs_attention(): void
    {
        $definition = new FlowDefinition('channel_setup', [
            new FlowStepDefinition('verify', 'Verify access', retriable: true),
            new FlowStepDefinition('webhook', 'Configure webhook'),
        ]);
        $state = new FlowState(
            FlowStatus::RUNNING,
            ['verify' => new StepState(FlowStepStatus::FAILED, error: 'Token expired', retriable: true)],
        );

        $result = (new FlowEvaluator())->evaluate($definition, $state);

        self::assertSame(FlowStatus::ATTENTION, $result->status);
        self::assertSame('verify', $result->canRetryStep);
        self::assertSame(['verify'], $result->failedSteps);
        self::assertSame('Token expired', $result->message);
    }

    public function test_non_critical_failure_does_not_block_completion(): void
    {
        $definition = new FlowDefinition('conversation_policy', [
            new FlowStepDefinition('connection', 'Connection', critical: true),
            new FlowStepDefinition('analytics', 'Analytics', critical: false),
        ]);
        $state = new FlowState(
            FlowStatus::RUNNING,
            [
                'connection' => new StepState(FlowStepStatus::COMPLETED),
                'analytics' => new StepState(FlowStepStatus::FAILED, error: 'Unavailable'),
            ],
        );

        $result = (new FlowEvaluator())->evaluate($definition, $state);

        self::assertSame(FlowStatus::COMPLETED, $result->status);
        self::assertSame(['analytics'], $result->failedSteps);
    }

    public function test_runner_executes_steps_and_respects_dependencies(): void
    {
        $definition = new FlowDefinition('setup', [
            new FlowStepDefinition('authorize', 'Authorize', retriable: true),
            new FlowStepDefinition('subscribe', 'Subscribe', dependsOn: ['authorize']),
        ]);

        $executor = new class implements FlowStepExecutor {
            /** @var list<string> */
            public array $executed = [];

            public function supports(FlowStepDefinition $step): bool
            {
                return in_array($step->id, ['authorize', 'subscribe'], true);
            }

            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                $this->executed[] = $step->id;

                return StepResult::completed(metadata: ['context' => $context->get('source')]);
            }
        };

        $result = (new FlowRunner())->run(
            $definition,
            new FlowState(FlowStatus::PENDING),
            new ArrayFlowContext(['source' => 'test']),
            [$executor],
        );

        self::assertSame(['authorize', 'subscribe'], $executor->executed);
        self::assertSame(FlowStatus::COMPLETED, $result->status);
        self::assertSame('test', $result->steps['subscribe']->metadata['context']);
    }

    public function test_runner_converts_executor_exceptions_to_retriable_failures(): void
    {
        $definition = new FlowDefinition('setup', [
            new FlowStepDefinition('authorize', 'Authorize', retriable: true),
        ]);
        $executor = new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return $step->id === 'authorize';
            }

            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                throw new RuntimeException('provider unavailable');
            }
        };

        $result = (new FlowRunner())->run($definition, new FlowState(FlowStatus::PENDING), new ArrayFlowContext(), [$executor]);

        self::assertSame(FlowStatus::ATTENTION, $result->status);
        self::assertSame('provider unavailable', $result->steps['authorize']->error);
        self::assertTrue($result->steps['authorize']->retriable);
    }

    public function test_action_dispatcher_requires_a_registered_enabled_action(): void
    {
        $handler = new class implements FlowActionHandler {
            public function supports(FlowAction $action): bool
            {
                return $action->key === 'retry';
            }

            public function handle(FlowAction $action, array $context = []): mixed
            {
                return $context['value'] ?? null;
            }
        };
        $registry = new class($handler) implements FlowActionRegistry {
            public function __construct(private readonly FlowActionHandler $handler)
            {
            }

            public function handlerFor(FlowAction $action): ?FlowActionHandler
            {
                return $this->handler->supports($action) ? $this->handler : null;
            }
        };

        self::assertSame(
            'ok',
            (new FlowActionDispatcher($registry))->dispatch(new FlowAction('retry', 'Retry'), ['value' => 'ok']),
        );
    }

    public function test_action_dispatcher_rejects_unauthorized_actions(): void
    {
        $authorizer = new class implements \Webong\WorkFlow\Contracts\FlowActionAuthorizer {
            public function allows(FlowAction $action, array $context = []): bool
            {
                return ($context['actor'] ?? null) === 'allowed';
            }
        };
        $registry = new class implements FlowActionRegistry {
            public function handlerFor(FlowAction $action): ?FlowActionHandler { return null; }
        };

        $this->expectException(RuntimeException::class);
        (new FlowActionDispatcher($registry, $authorizer))->dispatch(new FlowAction('retry', 'Retry'), ['actor' => 'denied']);
    }

    public function test_state_transition_and_presentation_are_framework_neutral(): void
    {
        $state = (new FlowStateTransition())->failed(new FlowState(FlowStatus::RUNNING), 'verify', 'Expired', true);
        $presentation = (new FlowPresentationFactory())->fromState(
            $state,
            action: new FlowAction('retry', 'Retry setup'),
        );

        self::assertNotNull($presentation);
        self::assertSame('warning', $presentation->toArray()['severity']);
        self::assertSame('retry', $presentation->actions[0]->key);
    }

    public function test_persisted_state_can_be_migrated_between_versions(): void
    {
        $migrator = new class implements FlowStateMigrator {
            public function fromVersion(): int { return 1; }

            public function toVersion(): int { return 2; }

            public function migrate(FlowState $state): FlowState
            {
                return new FlowState($state->status, $state->steps, version: 2);
            }
        };

        $state = (new FlowStateMigrationRunner())->migrate(
            new FlowState(FlowStatus::PENDING),
            2,
            [$migrator],
        );

        self::assertSame(2, $state->version);
    }

    public function test_state_store_and_serializer_round_trip_state(): void
    {
        $state = new FlowState(
            FlowStatus::ATTENTION,
            ['verify' => new StepState(FlowStepStatus::FAILED, error: 'Expired', attempts: 2, nextRetryAt: 123)],
            canRetryStep: 'verify',
            version: 1,
        );
        $serializer = new DefaultFlowStateSerializer();
        $store = new InMemoryFlowStateStore();

        $store->put('setup', $serializer->deserialize($serializer->serialize($state)));

        self::assertSame(FlowStatus::ATTENTION, $store->get('setup')?->status);
        self::assertSame(2, $store->get('setup')?->steps['verify']->attempts);
    }

    public function test_legacy_state_payload_remains_readable(): void
    {
        $state = (new DefaultFlowStateSerializer())->deserialize([
            'status' => 'running',
            'steps' => ['verify' => ['status' => 'completed']],
            'message' => 'Legacy state',
        ]);

        self::assertSame(FlowStatus::RUNNING, $state->status);
        self::assertSame(FlowStepStatus::COMPLETED, $state->steps['verify']->status);
        self::assertSame('Legacy state', $state->message);
        self::assertSame(1, $state->version);
    }

    public function test_runner_emits_lifecycle_events_and_supports_deferred_steps(): void
    {
        $events = new CollectingFlowEventSink();
        $executor = new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool { return true; }

            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                return StepResult::deferred('Waiting for provider callback.');
            }
        };

        $state = (new FlowRunner(events: $events))->run(
            new FlowDefinition('async_setup', [new FlowStepDefinition('authorize', 'Authorize')]),
            new FlowState(FlowStatus::PENDING),
            new ArrayFlowContext(),
            [$executor],
        );

        self::assertSame(FlowStepStatus::PENDING, $state->steps['authorize']->status);
        self::assertSame('step_started', $events->events()[1]->type->value);
        self::assertSame('step_deferred', $events->events()[2]->type->value);
    }

    public function test_presentation_covers_progress_and_completed_states(): void
    {
        $factory = new FlowPresentationFactory();

        self::assertSame('info', $factory->fromState(new FlowState(FlowStatus::RUNNING))->severity);
        self::assertSame('success', $factory->fromState(new FlowState(FlowStatus::COMPLETED))?->severity);
    }

    public function test_action_context_preserves_actor_and_resource_scope(): void
    {
        $context = new FlowActionContext(actor: 'operator-1', resource: 'channel-1', attributes: ['tenant' => 'tenant-1']);

        self::assertSame('operator-1', $context->toArray()['actor']);
        self::assertSame('tenant-1', $context->toArray()['attributes']['tenant']);
    }

    public function test_retry_policy_exhaustion_blocks_retry_and_respects_backoff(): void
    {
        $definition = new FlowDefinition('retrying', [
            new FlowStepDefinition('verify', 'Verify', retryPolicy: new FlowRetryPolicy(maxAttempts: 2, backoffSeconds: 60)),
        ]);
        $state = new FlowState(FlowStatus::RUNNING, [
            'verify' => new StepState(FlowStepStatus::FAILED, error: 'Failed', retriable: true, attempts: 2, nextRetryAt: time() - 1),
        ]);

        $result = (new FlowEvaluator())->evaluate($definition, $state);

        self::assertSame(FlowStatus::BLOCKED, $result->status);
        self::assertNull($result->canRetryStep);
    }

    public function test_deferred_completion_is_correlated_and_idempotent(): void
    {
        $initial = new FlowState(FlowStatus::RUNNING, [
            'subscribe' => new StepState(FlowStepStatus::PENDING, metadata: ['deferred' => true]),
        ]);
        $completion = new FlowDeferredCompletion('setup', 'subscribe', 'callback-1', StepResult::completed('Subscribed'));
        $transition = new FlowStateTransition();

        $definition = new FlowDefinition('setup', [new FlowStepDefinition('subscribe', 'Subscribe')]);
        $completed = $transition->complete($definition, $initial, $completion);
        $replayed = $transition->complete($definition, $completed, $completion);

        self::assertSame(FlowStepStatus::COMPLETED, $completed->steps['subscribe']->status);
        self::assertSame(FlowStatus::COMPLETED, $completed->status);
        self::assertSame($completed->toArray(), $replayed->toArray());
    }

    public function test_deferred_pending_completion_remains_eligible_for_a_later_callback(): void
    {
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('subscribe', 'Subscribe')]);
        $initial = new FlowState(FlowStatus::RUNNING, [
            'subscribe' => new StepState(FlowStepStatus::PENDING, attempts: 1, metadata: [
                'deferred' => true,
                'provider_id' => 'provider-1',
            ]),
        ]);
        $transition = new FlowStateTransition();

        $pending = $transition->complete(
            $definition,
            $initial,
            new FlowDeferredCompletion('setup', 'subscribe', 'callback-pending', StepResult::pending('Still processing')),
        );
        $completed = $transition->complete(
            $definition,
            $pending,
            new FlowDeferredCompletion('setup', 'subscribe', 'callback-completed', StepResult::completed('Subscribed')),
        );

        self::assertTrue($pending->steps['subscribe']->metadata['deferred']);
        self::assertSame('provider-1', $pending->steps['subscribe']->metadata['provider_id']);
        self::assertSame(1, $pending->steps['subscribe']->attempts);
        self::assertSame(FlowStatus::RUNNING, $pending->status);
        self::assertSame(FlowStatus::COMPLETED, $completed->status);
    }

    public function test_deferred_completion_applies_failed_results(): void
    {
        $state = new FlowState(FlowStatus::RUNNING, [
            'subscribe' => new StepState(FlowStepStatus::PENDING, metadata: ['deferred' => true]),
        ]);
        $completion = new FlowDeferredCompletion('setup', 'subscribe', 'callback-failed', StepResult::failed('Subscription failed', true));

        $result = (new FlowStateTransition())->complete(
            new FlowDefinition('setup', [new FlowStepDefinition('subscribe', 'Subscribe')]),
            $state,
            $completion,
        );

        self::assertSame(FlowStepStatus::FAILED, $result->steps['subscribe']->status);
        self::assertSame('Subscription failed', $result->steps['subscribe']->error);
        self::assertSame(FlowStatus::ATTENTION, $result->status);
    }

    public function test_deferred_completion_rejects_wrong_flow(): void
    {
        $transition = new FlowStateTransition();
        $definition = new FlowDefinition('setup', [new FlowStepDefinition('subscribe', 'Subscribe')]);
        $completion = new FlowDeferredCompletion('other', 'subscribe', 'callback-1', StepResult::completed());

        $this->expectException(\InvalidArgumentException::class);
        $transition->complete($definition, new FlowState(FlowStatus::RUNNING), $completion);
    }

    public function test_deferred_completion_rejects_a_step_that_is_not_deferred(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FlowStateTransition())->complete(
            new FlowDefinition('setup', [new FlowStepDefinition('subscribe', 'Subscribe')]),
            new FlowState(FlowStatus::RUNNING, [
                'subscribe' => new StepState(FlowStepStatus::PENDING),
            ]),
            new FlowDeferredCompletion('setup', 'subscribe', 'callback-1', StepResult::completed()),
        );
    }

    public function test_deferred_completion_rejects_state_steps_missing_from_the_definition(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FlowStateTransition())->complete(
            new FlowDefinition('setup'),
            new FlowState(FlowStatus::RUNNING, [
                'subscribe' => new StepState(FlowStepStatus::PENDING, metadata: ['deferred' => true]),
            ]),
            new FlowDeferredCompletion('setup', 'subscribe', 'callback-1', StepResult::completed()),
        );
    }

    public function test_reset_clears_a_step_for_replay_without_mutating_the_original(): void
    {
        $state = new FlowState(FlowStatus::ATTENTION, [
            'verify' => new StepState(FlowStepStatus::FAILED, error: 'Expired', attempts: 1),
        ]);

        $reset = (new FlowStateTransition())->reset($state, 'verify');

        self::assertSame(FlowStepStatus::PENDING, $reset->steps['verify']->status);
        self::assertSame(FlowStepStatus::FAILED, $state->steps['verify']->status);
    }

    public function test_event_order_is_stable_for_deferred_execution(): void
    {
        $events = new CollectingFlowEventSink();
        $executor = new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool { return true; }
            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                return StepResult::deferred();
            }
        };

        (new FlowRunner(events: $events))->run(
            new FlowDefinition('ordered', [new FlowStepDefinition('one', 'One')]),
            new FlowState(FlowStatus::PENDING),
            new ArrayFlowContext(),
            [$executor],
        );

        self::assertSame(
            ['started', 'step_started', 'step_deferred', 'started'],
            array_map(static fn (\Webong\WorkFlow\ValueObjects\FlowEvent $event): string => $event->type->value, $events->events()),
        );
    }
}
