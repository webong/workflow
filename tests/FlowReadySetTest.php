<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\FlowReadySet;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepState;

final class FlowReadySetTest extends TestCase
{
    public function test_it_returns_every_independent_step_ready_at_the_same_snapshot(): void
    {
        $definition = new FlowDefinition('parallel', [
            new FlowStepDefinition('prepare', 'Prepare'),
            new FlowStepDefinition('charge', 'Charge', dependsOn: ['prepare']),
            new FlowStepDefinition('reserve', 'Reserve', dependsOn: ['prepare']),
            new FlowStepDefinition('notify', 'Notify', dependsOn: ['charge', 'reserve']),
        ]);
        $state = (new FlowState(FlowStatus::RUNNING))->withStep(
            'prepare',
            new StepState(FlowStepStatus::COMPLETED),
        );

        $ready = (new FlowReadySet())->steps($definition, $state, now: 1_000);

        self::assertSame(['charge', 'reserve'], array_map(static fn (FlowStepDefinition $step): string => $step->id, $ready));
    }

    public function test_it_excludes_waiting_running_terminal_and_backoff_steps(): void
    {
        $definition = new FlowDefinition('eligibility', [
            new FlowStepDefinition('waiting', 'Waiting'),
            new FlowStepDefinition('running', 'Running'),
            new FlowStepDefinition('completed', 'Completed'),
            new FlowStepDefinition('backoff', 'Backoff', retriable: true),
            new FlowStepDefinition('retry', 'Retry', retriable: true),
            new FlowStepDefinition('terminal', 'Terminal'),
        ]);
        $state = new FlowState(FlowStatus::RUNNING, steps: [
            'waiting' => new StepState(metadata: ['deferred' => true]),
            'running' => new StepState(FlowStepStatus::RUNNING),
            'completed' => new StepState(FlowStepStatus::COMPLETED),
            'backoff' => new StepState(FlowStepStatus::FAILED, retriable: true, attempts: 1, nextRetryAt: 1_001),
            'retry' => new StepState(FlowStepStatus::FAILED, retriable: true, attempts: 1, nextRetryAt: 999),
            'terminal' => new StepState(FlowStepStatus::FAILED, retriable: false, attempts: 1),
        ]);

        $ready = (new FlowReadySet())->steps($definition, $state, now: 1_000);

        self::assertSame(['retry'], array_map(static fn (FlowStepDefinition $step): string => $step->id, $ready));
    }

    public function test_terminal_runs_have_no_ready_steps(): void
    {
        $definition = new FlowDefinition('done', [new FlowStepDefinition('one', 'One')]);

        self::assertSame([], (new FlowReadySet())->steps($definition, new FlowState(FlowStatus::COMPLETED)));
        self::assertSame([], (new FlowReadySet())->steps($definition, new FlowState(FlowStatus::CANCELLED)));
    }
}
