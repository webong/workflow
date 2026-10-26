<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowExecutionStatus;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Laravel\LaravelQueueFlowExecutionDriver;
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\InlineFlowExecutionDriver;
use Webong\WorkFlow\Services\QueuedFlowExecutionDriver;
use Webong\WorkFlow\ValueObjects\FlowChildRunReceipt;
use Webong\WorkFlow\ValueObjects\FlowChildRunRequest;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

final class FlowExecutionTest extends TestCase
{
    public function test_dispatcher_routes_to_the_selected_driver_and_records_it(): void
    {
        $seen = false;
        $driver = new LaravelQueueFlowExecutionDriver(
            dispatch: static function (FlowExecutionRequest $request) use (&$seen): string {
                $seen = $request->state->metadata['execution_driver'] === 'queue';

                return 'queue-execution-1';
            },
        );

        $receipt = (new FlowExecutionDispatcher([$driver]))->dispatch(new FlowExecutionRequest(
            definition: $this->definition(),
            state: new FlowState(FlowStatus::PENDING),
            driver: 'queue',
        ));

        self::assertTrue($seen);
        self::assertSame('queue', $receipt->driver);
        self::assertSame(FlowExecutionStatus::DISPATCHED, $receipt->status);
        self::assertSame('queue-execution-1', $receipt->executionId);
    }

    public function test_dispatcher_rejects_switching_a_running_execution_to_another_driver(): void
    {
        $dispatcher = new FlowExecutionDispatcher([
            new LaravelQueueFlowExecutionDriver(
                dispatch: static fn (FlowExecutionRequest $request): string => 'queue-execution-1',
            ),
            new InlineFlowExecutionDriver(new FlowRunner(), [$this->executor()]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("already assigned to the 'queue' driver");

        $dispatcher->dispatch(new FlowExecutionRequest(
            definition: $this->definition(),
            state: new FlowState(FlowStatus::PENDING, metadata: ['execution_driver' => 'queue']),
            driver: 'inline',
        ));
    }

    public function test_inline_driver_runs_the_same_step_executors_as_the_normal_runner(): void
    {
        $receipt = (new FlowExecutionDispatcher([
            new InlineFlowExecutionDriver(new FlowRunner(), [$this->executor()]),
        ]))->dispatch(new FlowExecutionRequest(
            definition: $this->definition(),
            state: new FlowState(FlowStatus::PENDING),
            context: ['source' => 'test'],
        ));

        self::assertSame(FlowExecutionStatus::COMPLETED, $receipt->status);
        self::assertSame(FlowStepStatus::COMPLETED, $receipt->state?->steps['authorize']->status);
        self::assertSame('inline', $receipt->state?->metadata['execution_driver']);
    }

    public function test_request_round_trips_the_dispatch_context(): void
    {
        $request = new FlowExecutionRequest(
            definition: $this->definition(),
            state: new FlowState(FlowStatus::PENDING),
            context: ['source' => 'api'],
            driver: 'queue',
            executionId: 'execution-1',
        );

        $roundTrip = FlowExecutionRequest::fromArray($request->toArray());

        self::assertSame($request->definition->toArray(), $roundTrip->definition->toArray());
        self::assertSame($request->state->toArray(), $roundTrip->state->toArray());
        self::assertSame($request->context, $roundTrip->context);
        self::assertSame('queue', $roundTrip->driver);
        self::assertSame('execution-1', $roundTrip->executionId);
    }

    public function test_framework_neutral_queue_driver_dispatches_a_serializable_request(): void
    {
        $payload = null;
        $driver = new QueuedFlowExecutionDriver(
            dispatch: static function (FlowExecutionRequest $request) use (&$payload): string {
                $payload = $request->toArray();
                return 'job-1';
            },
        );

        $receipt = $driver->dispatch(new FlowExecutionRequest(
            definition: $this->definition(),
            state: new FlowState(FlowStatus::PENDING),
            context: ['source' => 'rpc'],
            driver: 'queue',
        ));

        self::assertIsArray($payload);
        self::assertSame('queue', $payload['driver']);
        self::assertSame('job-1', $receipt->executionId);
        self::assertSame(FlowExecutionStatus::DISPATCHED, $receipt->status);
    }

    public function test_child_run_transport_values_round_trip(): void
    {
        $request = new FlowChildRunRequest(
            childFlowKey: 'catalog/child@1.0.0',
            childRunId: 'parent:child:2',
            input: ['id' => 42],
            parentFlowKey: 'parent',
            parentRunId: 'run-1',
            parentStepId: 'child',
            parentAttempt: 2,
        );
        $requestRoundTrip = FlowChildRunRequest::fromArray($request->toArray());
        self::assertEquals($request, $requestRoundTrip);

        $receipt = new FlowChildRunReceipt(
            $request->childFlowKey,
            $request->childRunId,
            FlowExecutionStatus::COMPLETED,
            \Webong\WorkFlow\ValueObjects\StepOutput::from(['ok' => true]),
        );
        self::assertEquals($receipt, FlowChildRunReceipt::fromArray($receipt->toArray()));
    }

    private function definition(): FlowDefinition
    {
        return new FlowDefinition('setup', [new FlowStepDefinition('authorize', 'Authorize')]);
    }

    private function executor(): FlowStepExecutor
    {
        return new class implements FlowStepExecutor {
            public function supports(FlowStepDefinition $step): bool
            {
                return $step->id === 'authorize';
            }

            public function execute(\Webong\WorkFlow\ValueObjects\FlowStepInvocation $invocation): StepResult
            {
                return StepResult::completed(metadata: ['source' => $invocation->context->get('source')]);
            }
        };
    }
}
