<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\OpenWorkflow;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Contracts\FlowChildRunDispatcher;
use Webong\WorkFlow\Enums\FlowExecutionStatus;
use Webong\WorkFlow\OpenWorkflow\OpenWorkflow;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsWorkflowContext;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsWorkflowRunExecutor;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsListenExecutor;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsSetStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Runtime\OwsWaitExecutor;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\ValueObjects\FlowChildRunReceipt;
use Webong\WorkFlow\ValueObjects\FlowChildRunRequest;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

final class OwsDurableRuntimeTest extends TestCase
{
    public function test_wait_records_a_successful_wakeup_deadline(): void
    {
        $report = (new OpenWorkflow())->registerOwsDefinition(<<<'YAML'
document:
  dsl: "1.0.3"
  namespace: com.example
  name: delayed
  version: "1.0.0"
do:
  - pause:
      wait:
        seconds: 5
YAML);
        self::assertSame([], $report->unsupported);
        self::assertNotNull($report->flowDefinition);
        $context = new OwsWorkflowContext(['order_id' => '42']);
        $state = (new FlowRunner())->run(
            $report->flowDefinition,
            (new FlowRun('wait-run', $report->flowDefinition))->initialState(),
            $context,
            [new OwsWaitExecutor(static fn (): int => 1_000)],
        );

        self::assertSame('pending', $state->steps['pause']->status->value);
        self::assertSame(1_005, $state->steps['pause']->deadlineAt);
        self::assertSame('complete', $state->steps['pause']->metadata['deadline_transition']);

        $completed = (new FlowStateTransition(clock: static fn (): int => 1_005))
            ->deadlineReached($report->flowDefinition, $state, 'pause');
        self::assertSame('completed', $completed->steps['pause']->status->value);
        self::assertSame(['order_id' => '42'], $completed->steps['pause']->output?->value);
        self::assertSame('completed', $completed->status->value);
    }

    public function test_listen_records_the_host_subscription_and_waits_for_a_callback(): void
    {
        $report = (new OpenWorkflow())->registerOwsDefinition(<<<'YAML'
document:
  dsl: "1.0.3"
  namespace: com.example
  name: event-driven
  version: "1.0.0"
do:
  - approved:
      listen:
        to:
          one:
            with:
              type: com.example.order.approved
        read: envelope
YAML);
        self::assertSame([], $report->unsupported);
        self::assertNotNull($report->flowDefinition);
        $state = (new FlowRunner())->run(
            $report->flowDefinition,
            (new FlowRun('listen-run', $report->flowDefinition))->initialState(),
            new OwsWorkflowContext([]),
            [new OwsListenExecutor()],
        );

        self::assertSame('pending', $state->steps['approved']->status->value);
        self::assertTrue($state->steps['approved']->metadata['deferred']);
        self::assertSame('envelope', $state->steps['approved']->metadata['ows_listen']['read']);
        self::assertSame(
            'com.example.order.approved',
            $state->steps['approved']->metadata['ows_listen']['to']['one']['with']['type'],
        );
        self::assertNull($state->steps['approved']->deadlineAt);
    }

    public function test_listen_foreach_is_reported_as_unsupported_instead_of_silently_ignored(): void
    {
        $report = (new OpenWorkflow())->registerOwsDefinition(<<<'YAML'
document:
  dsl: "1.0.3"
  namespace: com.example
  name: event-batch
  version: "1.0.0"
do:
  - approved:
      listen:
        to:
          one:
            with:
              type: com.example.order.approved
      foreach:
        item: event
        do:
          - collect:
              set:
                id: "${ .id }"
YAML);

        self::assertSame(['listen:foreach'], array_map(
            static fn ($feature): string => $feature->feature,
            $report->unsupported,
        ));
    }

    public function test_run_workflow_dispatches_a_correlated_child_and_waits_for_its_completion(): void
    {
        $report = (new OpenWorkflow())->registerOwsDefinition(<<<'YAML'
version: "1.0.3"
document:
  namespace: com.example
  name: parent
  version: "1.0.0"
do:
  - invoke-child:
      run:
        workflow:
          namespace: com.example
          name: child
          version: "2.0.0"
          input:
            order_id: "${ .order.id }"
            options:
              notify: "${ .notify }"
        await: true
  - use-child-output:
      set:
        child_ok: "${ .ok }"
YAML);

        self::assertTrue($report->valid);
        self::assertSame([], $report->unsupported);
        self::assertNotNull($report->flowDefinition);

        $dispatcher = new class implements FlowChildRunDispatcher {
            public ?FlowChildRunRequest $request = null;

            public function dispatch(FlowChildRunRequest $request): FlowChildRunReceipt
            {
                $this->request = $request;

                return new FlowChildRunReceipt(
                    childFlowKey: $request->childFlowKey,
                    childRunId: $request->childRunId,
                    status: FlowExecutionStatus::DISPATCHED,
                );
            }
        };
        $context = new OwsWorkflowContext(['order' => ['id' => 'order-42'], 'notify' => true]);
        $state = (new FlowRunner())->run(
            $report->flowDefinition,
            (new FlowRun('parent-run', $report->flowDefinition))->initialState(),
            $context,
            [new OwsWorkflowRunExecutor($dispatcher)],
        );

        self::assertNotNull($dispatcher->request);
        self::assertSame('com.example/child@2.0.0', $dispatcher->request->childFlowKey);
        self::assertSame('parent-run:invoke-child:1', $dispatcher->request->childRunId);
        self::assertSame('com.example/parent@1.0.0', $dispatcher->request->parentFlowKey);
        self::assertSame('parent-run', $dispatcher->request->parentRunId);
        self::assertSame('invoke-child', $dispatcher->request->parentStepId);
        self::assertSame(1, $dispatcher->request->parentAttempt);
        self::assertSame([
            'order_id' => 'order-42',
            'options' => ['notify' => true],
        ], $dispatcher->request->input);
        self::assertTrue($dispatcher->request->await);
        self::assertSame('pending', $state->steps['invoke-child']->status->value);
        self::assertTrue($state->steps['invoke-child']->metadata['deferred']);
        self::assertSame('parent-run:invoke-child:1', $state->steps['invoke-child']->metadata['child_run_id']);

        $state = (new FlowStateTransition())->complete(
            $report->flowDefinition,
            $state,
            new FlowDeferredCompletion(
                flowKey: $report->flowDefinition->key,
                stepId: 'invoke-child',
                idempotencyKey: 'child-finished',
                result: StepResult::completed(output: StepOutput::from(['ok' => true])),
                runId: 'parent-run',
                attempt: 1,
            ),
        );
        $resumedContext = OwsWorkflowContext::resume(
            ['order' => ['id' => 'order-42'], 'notify' => true],
            $report->flowDefinition,
            $state,
        );
        $state = (new FlowRunner())->run(
            $report->flowDefinition,
            $state,
            $resumedContext,
            [new OwsWorkflowRunExecutor($dispatcher), new OwsSetStepExecutor()],
        );
        self::assertSame('completed', $state->status->value);
        self::assertSame(['child_ok' => true], $resumedContext->lastOutput());
    }

    public function test_completed_child_output_completes_the_parent_step(): void
    {
        $report = (new OpenWorkflow())->registerOwsDefinition(<<<'YAML'
document:
  dsl: "1.0.3"
  namespace: com.example
  name: parent
  version: "1.0.0"
do:
  - invoke-child:
      run:
        workflow:
          namespace: com.example
          name: child
          version: "2.0.0"
YAML);
        self::assertNotNull($report->flowDefinition);
        $dispatcher = new class implements FlowChildRunDispatcher {
            public function dispatch(FlowChildRunRequest $request): FlowChildRunReceipt
            {
                return new FlowChildRunReceipt(
                    $request->childFlowKey,
                    $request->childRunId,
                    FlowExecutionStatus::COMPLETED,
                    StepOutput::from(['ok' => true]),
                );
            }
        };
        $context = new OwsWorkflowContext([]);

        $state = (new FlowRunner())->run(
            $report->flowDefinition,
            (new FlowRun('parent-run', $report->flowDefinition))->initialState(),
            $context,
            [new OwsWorkflowRunExecutor($dispatcher)],
        );

        self::assertSame('completed', $state->steps['invoke-child']->status->value);
        self::assertSame(['ok' => true], $state->steps['invoke-child']->output?->value);
        self::assertSame(['ok' => true], $context->lastOutput());
    }
}
