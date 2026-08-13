<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zorvia\WebFlow\Contracts\FlowActionHandler;
use Zorvia\WebFlow\Contracts\FlowActionRegistry;
use Zorvia\WebFlow\Contracts\FlowContext;
use Zorvia\WebFlow\Contracts\FlowStepExecutor;
use Zorvia\WebFlow\Enums\FlowStatus;
use Zorvia\WebFlow\Enums\StepStatus;
use Zorvia\WebFlow\Services\FlowEvaluator;
use Zorvia\WebFlow\Services\FlowActionDispatcher;
use Zorvia\WebFlow\Services\FlowPresentationFactory;
use Zorvia\WebFlow\Services\FlowRunner;
use Zorvia\WebFlow\Services\FlowStateTransition;
use Zorvia\WebFlow\Services\FlowStateMigrationRunner;
use Zorvia\WebFlow\Services\InMemoryFlowStateStore;
use Zorvia\WebFlow\Services\DefaultFlowStateSerializer;
use Zorvia\WebFlow\Services\CollectingFlowEventSink;
use Zorvia\WebFlow\Contracts\FlowStateMigrator;
use Zorvia\WebFlow\ValueObjects\FlowAction;
use Zorvia\WebFlow\ValueObjects\ArrayFlowContext;
use Zorvia\WebFlow\ValueObjects\FlowDefinition;
use Zorvia\WebFlow\ValueObjects\FlowState;
use Zorvia\WebFlow\ValueObjects\StepDefinition;
use Zorvia\WebFlow\ValueObjects\StepResult;
use Zorvia\WebFlow\ValueObjects\StepState;
use Zorvia\WebFlow\ValueObjects\FlowActionContext;
use Zorvia\WebFlow\Enums\PresentationKind;

final class FlowEvaluatorTest extends TestCase
{
    public function test_a_retriable_critical_failure_needs_attention(): void
    {
        $definition = new FlowDefinition('channel_setup', [
            new StepDefinition('verify', 'Verify access', retriable: true),
            new StepDefinition('webhook', 'Configure webhook'),
        ]);
        $state = new FlowState(
            FlowStatus::RUNNING,
            ['verify' => new StepState(StepStatus::FAILED, error: 'Token expired', retriable: true)],
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
            new StepDefinition('connection', 'Connection', critical: true),
            new StepDefinition('analytics', 'Analytics', critical: false),
        ]);
        $state = new FlowState(
            FlowStatus::RUNNING,
            [
                'connection' => new StepState(StepStatus::COMPLETED),
                'analytics' => new StepState(StepStatus::FAILED, error: 'Unavailable'),
            ],
        );

        $result = (new FlowEvaluator())->evaluate($definition, $state);

        self::assertSame(FlowStatus::COMPLETED, $result->status);
        self::assertSame(['analytics'], $result->failedSteps);
    }

    public function test_runner_executes_steps_and_respects_dependencies(): void
    {
        $definition = new FlowDefinition('setup', [
            new StepDefinition('authorize', 'Authorize', retriable: true),
            new StepDefinition('subscribe', 'Subscribe', dependsOn: ['authorize']),
        ]);

        $executor = new class implements FlowStepExecutor {
            /** @var list<string> */
            public array $executed = [];

            public function supports(StepDefinition $step): bool
            {
                return in_array($step->id, ['authorize', 'subscribe'], true);
            }

            public function execute(StepDefinition $step, FlowContext $context, StepState $previous): StepResult
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
            new StepDefinition('authorize', 'Authorize', retriable: true),
        ]);
        $executor = new class implements FlowStepExecutor {
            public function supports(StepDefinition $step): bool
            {
                return $step->id === 'authorize';
            }

            public function execute(StepDefinition $step, FlowContext $context, StepState $previous): StepResult
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

    public function test_state_transition_and_presentation_are_framework_neutral(): void
    {
        $state = (new FlowStateTransition())->failed(new FlowState(FlowStatus::RUNNING), 'verify', 'Expired', true);
        $presentation = (new FlowPresentationFactory())->fromState(
            $state,
            action: new FlowAction('retry', 'Retry setup'),
        );

        self::assertNotNull($presentation);
        self::assertSame('banner', $presentation->toArray()['kind']);
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
            ['verify' => new StepState(StepStatus::FAILED, error: 'Expired', attempts: 2, nextRetryAt: 123)],
            canRetryStep: 'verify',
            version: 1,
        );
        $serializer = new DefaultFlowStateSerializer();
        $store = new InMemoryFlowStateStore();

        $store->put('setup', $serializer->deserialize($serializer->serialize($state)));

        self::assertSame(FlowStatus::ATTENTION, $store->get('setup')?->status);
        self::assertSame(2, $store->get('setup')?->steps['verify']->attempts);
    }

    public function test_runner_emits_lifecycle_events_and_supports_deferred_steps(): void
    {
        $events = new CollectingFlowEventSink();
        $executor = new class implements FlowStepExecutor {
            public function supports(StepDefinition $step): bool { return true; }

            public function execute(StepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                return StepResult::deferred('Waiting for provider callback.');
            }
        };

        $state = (new FlowRunner(events: $events))->run(
            new FlowDefinition('async_setup', [new StepDefinition('authorize', 'Authorize')]),
            new FlowState(FlowStatus::PENDING),
            new ArrayFlowContext(),
            [$executor],
        );

        self::assertSame(StepStatus::PENDING, $state->steps['authorize']->status);
        self::assertSame('step_started', $events->events()[1]->type->value);
        self::assertSame('step_started', $events->events()[2]->type->value);
    }

    public function test_presentation_covers_progress_and_completed_states(): void
    {
        $factory = new FlowPresentationFactory();

        self::assertSame(PresentationKind::INLINE, $factory->fromState(new FlowState(FlowStatus::RUNNING))?->kind);
        self::assertSame('success', $factory->fromState(new FlowState(FlowStatus::COMPLETED))?->severity);
    }

    public function test_action_context_preserves_actor_and_resource_scope(): void
    {
        $context = new FlowActionContext(actor: 'operator-1', resource: 'channel-1', attributes: ['tenant' => 'tenant-1']);

        self::assertSame('operator-1', $context->toArray()['actor']);
        self::assertSame('tenant-1', $context->toArray()['attributes']['tenant']);
    }
}
