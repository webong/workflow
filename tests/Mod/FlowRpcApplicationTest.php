<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Mod;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowDefinitionProvider;
use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Contracts\ForgettableFlowStateStore;
use Webong\WorkFlow\Mod\FlowRpcApplication;
use Webong\WorkFlow\Mod\RpcFailure;
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\InMemoryFlowStateStore;
use Webong\WorkFlow\Services\InlineFlowExecutionDriver;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

final class FlowRpcApplicationTest extends TestCase
{
    public function testItStartsReadsAndCompletesADeferredFlowIdempotently(): void
    {
        $executor = new class implements FlowStepExecutor {
            public int $calls = 0;

            public function supports(FlowStepDefinition $step): bool
            {
                return $step->id === 'approve';
            }

            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                $this->calls++;

                return StepResult::deferred('Awaiting approval');
            }
        };

        $application = $this->application($executor);
        $params = ['flow_key' => 'demo', 'run_id' => 'run-1', 'subject' => ['type' => 'order', 'id' => '42']];

        self::assertNull($application->invoke('flow.get', $params)['state']);
        $started = $application->invoke('flow.start', $params);
        self::assertSame('pending', $started['state']['steps']['approve']['status']);
        self::assertSame('inline', $started['state']['metadata']['execution_driver']);

        $application->invoke('flow.start', $params);
        self::assertSame(1, $executor->calls);

        $complete = [...$params, 'step_id' => 'approve', 'attempt' => 1, 'idempotency_key' => 'approval-1', 'result' => ['status' => 'completed']];
        $completed = $application->invoke('flow.complete', $complete);
        self::assertSame('completed', $completed['state']['steps']['approve']['status']);
        self::assertSame($completed, $application->invoke('flow.complete', $complete));
        self::assertSame($completed, $application->invoke('flow.get', $params));
    }

    public function testItRejectsUnknownMethodsAndNonInlineExecution(): void
    {
        $application = $this->application(new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return true;
            }

            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                return StepResult::completed();
            }
        });

        try {
            $application->invoke('flow.unknown', []);
            self::fail('Unknown method was accepted');
        } catch (RpcFailure $exception) {
            self::assertSame(-32601, $exception->rpcCode);
        }

        $this->expectException(RpcFailure::class);
        $application->invoke('flow.start', [
            'flow_key' => 'demo',
            'subject' => ['type' => 'order', 'id' => '42'],
            'driver' => 'temporal',
        ]);
    }

    public function test_runs_pin_the_definition_and_context_and_resume_explicitly(): void
    {
        $provider = new class implements FlowDefinitionProvider {
            public int $version = 1;
            public function definition(string $flowKey): FlowDefinition
            {
                return new FlowDefinition('demo', [
                    new FlowStepDefinition('approve', 'Approve'),
                    new FlowStepDefinition('notify-v'.$this->version, 'Notify', dependsOn: ['approve']),
                ], version: $this->version);
            }
        };
        $executor = new class implements FlowStepExecutor {
            public array $calls = [];
            public function supports(FlowStepDefinition $step): bool { return true; }
            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                $this->calls[] = [$step->id, $context->get('source')];
                return $step->id === 'approve' ? StepResult::deferred() : StepResult::completed();
            }
        };
        $app = $this->application($executor, $provider);
        $params = ['flow_key' => 'demo', 'run_id' => 'one', 'subject' => ['type' => 'order', 'id' => '42']];
        $started = $app->invoke('flow.start', [...$params, 'context' => ['source' => 'original']]);
        self::assertArrayNotHasKey('_rpc_context', $started['state']['metadata']);
        $app->invoke('flow.resume', $params);
        self::assertCount(1, $executor->calls);
        $provider->version = 2;
        $completed = $app->invoke('flow.complete', [...$params, 'step_id' => 'approve', 'attempt' => 1, 'idempotency_key' => 'approved', 'result' => ['status' => 'completed']]);
        self::assertSame('running', $completed['state']['status']);
        self::assertCount(1, $executor->calls);
        $resumed = $app->invoke('flow.resume', $params);
        self::assertSame('completed', $resumed['state']['status']);
        self::assertSame(1, $resumed['state']['run']['definition']['version']);
        self::assertSame(['notify-v1', 'original'], $executor->calls[1]);
        $app->invoke('flow.resume', $params);
        self::assertCount(2, $executor->calls);
        $second = $app->invoke('flow.start', [...$params, 'run_id' => 'two']);
        self::assertSame(2, $second['state']['run']['definition']['version']);
        self::assertSame($resumed, $app->invoke('flow.get', $params));
    }

    public function test_execution_runs_outside_mutation_and_a_claim_prevents_overlapping_passes(): void
    {
        $storage = new class implements ForgettableFlowStateStore {
            public bool $locked = false;
            private array $states = [];
            public function get(string $flowKey): ?\Webong\WorkFlow\ValueObjects\FlowState { return $this->states[$flowKey] ?? null; }
            public function put(string $flowKey, \Webong\WorkFlow\ValueObjects\FlowState $state): void { $this->states[$flowKey] = $state; }
            public function forget(string $flowKey): void { unset($this->states[$flowKey]); }
            public function mutate(string $flowKey, \Closure $transition): \Webong\WorkFlow\ValueObjects\FlowState
            {
                self::assertUnlocked($this->locked);
                $this->locked = true;
                try {
                    $state = $transition($this->get($flowKey));
                    $this->put($flowKey, $state);
                    return $state;
                } finally {
                    $this->locked = false;
                }
            }
            private static function assertUnlocked(bool $locked): void
            {
                if ($locked) { throw new \RuntimeException('Nested lock'); }
            }
        };
        $stores = new class($storage) implements FlowStateStoreFactory {
            public function __construct(private ForgettableFlowStateStore $storage) {}
            public function for(FlowStateSubject $subject): ForgettableFlowStateStore { return $this->storage; }
        };
        $params = ['flow_key' => 'demo', 'run_id' => 'one', 'subject' => ['type' => 'order', 'id' => '42']];
        $executor = new class($storage, $params) implements FlowStepExecutor {
            public ?FlowRpcApplication $app = null;
            public bool $sawConflict = false;
            public function __construct(private ForgettableFlowStateStore $storage, private array $params) {}
            public function supports(FlowStepDefinition $step): bool { return true; }
            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                TestCase::assertFalse($this->storage->locked);
                try {
                    $this->app->invoke('flow.resume', $this->params);
                    TestCase::fail('Overlapping pass accepted');
                } catch (RpcFailure $exception) {
                    $this->sawConflict = $exception->rpcCode === -32009;
                }
                return StepResult::deferred();
            }
        };
        $app = $executor->app = $this->application($executor, stores: $stores);
        $app->invoke('flow.start', $params);
        self::assertTrue($executor->sawConflict);
        $cancelled = $app->invoke('flow.cancel', $params);
        self::assertSame('cancelled', $cancelled['state']['status']);
        self::assertSame($cancelled, $app->invoke('flow.resume', $params));
    }

    public function test_failed_result_persistence_leaves_a_claim_and_does_not_replay(): void
    {
        $storage = new class implements ForgettableFlowStateStore {
            private InMemoryFlowStateStore $inner;
            public function __construct() { $this->inner = new InMemoryFlowStateStore(); }
            public function get(string $flowKey): ?\Webong\WorkFlow\ValueObjects\FlowState { return $this->inner->get($flowKey); }
            public function put(string $flowKey, \Webong\WorkFlow\ValueObjects\FlowState $state): void { $this->inner->put($flowKey, $state); }
            public function forget(string $flowKey): void { $this->inner->forget($flowKey); }
            public function mutate(string $flowKey, \Closure $transition): \Webong\WorkFlow\ValueObjects\FlowState
            {
                return $this->inner->mutate($flowKey, static function (?\Webong\WorkFlow\ValueObjects\FlowState $current) use ($transition): \Webong\WorkFlow\ValueObjects\FlowState {
                    $next = $transition($current);
                    if (($current?->metadata['_rpc_claim'] ?? null) !== null && ($next->metadata['_rpc_claim'] ?? null) === null) {
                        throw new \RuntimeException('Simulated database outage on final save');
                    }
                    return $next;
                });
            }
        };
        $stores = new class($storage) implements FlowStateStoreFactory {
            public function __construct(private ForgettableFlowStateStore $storage) {}
            public function for(FlowStateSubject $subject): ForgettableFlowStateStore { return $this->storage; }
        };
        $executor = new class implements FlowStepExecutor {
            public int $calls = 0;
            public function supports(FlowStepDefinition $step): bool { return true; }
            public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
            {
                $this->calls++;
                return StepResult::completed();
            }
        };
        $app = $this->application($executor, stores: $stores);
        $params = ['flow_key' => 'demo', 'run_id' => 'one', 'subject' => ['type' => 'order', 'id' => '42']];
        try {
            $app->invoke('flow.start', $params);
            self::fail('Final save did not fail');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated database outage on final save', $exception->getMessage());
        }
        $saved = $app->invoke('flow.get', $params);
        self::assertNotNull($saved['state']['execution_claim']);
        self::assertSame($saved, $app->invoke('flow.start', $params));
        try {
            $app->invoke('flow.resume', $params);
            self::fail('Interrupted execution was replayed');
        } catch (RpcFailure $exception) {
            self::assertSame(-32009, $exception->rpcCode);
        }
        self::assertSame(1, $executor->calls);
    }

    private function application(FlowStepExecutor $executor, ?FlowDefinitionProvider $definitions = null, ?FlowStateStoreFactory $stores = null): FlowRpcApplication
    {
        $definitions ??= new class implements FlowDefinitionProvider {
            public function definition(string $flowKey): FlowDefinition
            {
                if ($flowKey !== 'demo') {
                    throw new InvalidArgumentException('Unknown flow');
                }

                return new FlowDefinition('demo', [new FlowStepDefinition('approve', 'Approve')]);
            }
        };

        $stores ??= new class implements FlowStateStoreFactory {
            /** @var array<string, InMemoryFlowStateStore> */
            private array $states = [];

            public function for(FlowStateSubject $subject): ForgettableFlowStateStore
            {
                $key = $subject->type.':'.$subject->id;

                return $this->states[$key] ??= new InMemoryFlowStateStore();
            }
        };

        return new FlowRpcApplication(
            $definitions,
            $stores,
            new FlowExecutionDispatcher([new InlineFlowExecutionDriver(new FlowRunner(), [$executor])]),
        );
    }
}
