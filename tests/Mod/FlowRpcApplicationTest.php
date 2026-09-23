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
        $params = ['flow_key' => 'demo', 'subject' => ['type' => 'order', 'id' => '42']];

        self::assertNull($application->invoke('flow.get', $params)['state']);
        $started = $application->invoke('flow.start', $params);
        self::assertSame('pending', $started['state']['steps']['approve']['status']);
        self::assertSame('inline', $started['state']['metadata']['execution_driver']);

        $application->invoke('flow.start', $params);
        self::assertSame(1, $executor->calls);

        $complete = [...$params, 'step_id' => 'approve', 'idempotency_key' => 'approval-1', 'result' => ['status' => 'completed']];
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

    private function application(FlowStepExecutor $executor): FlowRpcApplication
    {
        $definitions = new class implements FlowDefinitionProvider {
            public function definition(string $flowKey): FlowDefinition
            {
                if ($flowKey !== 'demo') {
                    throw new InvalidArgumentException('Unknown flow');
                }

                return new FlowDefinition('demo', [new FlowStepDefinition('approve', 'Approve')]);
            }
        };

        $stores = new class implements FlowStateStoreFactory {
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
