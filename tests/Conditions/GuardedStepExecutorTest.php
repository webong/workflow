<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Conditions;

use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Conditions\GuardedStepExecutor;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepResult;

final class GuardedStepExecutorTest extends TestCase
{
    public function testBranchSkipsWithoutEffectsAndDependentsStillProgress(): void
    {
        $effects = new class implements FlowStepExecutor {
            public int $calls=0;
            public function supports(FlowStepDefinition $step): bool { return true; }
            public function execute(FlowStepInvocation $invocation): StepResult { $this->calls++; return StepResult::completed(); }
        };
        $definition = new FlowDefinition('branch', [
            new FlowStepDefinition('optional','Optional', metadata:['when'=>['syntax'=>'jq','expression'=>'.context.enabled == true']]),
            new FlowStepDefinition('after','After', dependsOn:['optional']),
        ]);
        $run = new FlowRun('branch-run',$definition);
        $state=(new FlowRunner())->run($definition,$run->initialState(),new ArrayFlowContext(['enabled'=>false]),[new GuardedStepExecutor($effects)]);
        self::assertSame(FlowStepStatus::SKIPPED,$state->steps['optional']->status);
        self::assertSame(FlowStatus::COMPLETED,$state->status);
        self::assertSame(1,$effects->calls);
    }

    public function testInvalidOrNonBooleanConditionsFailWithoutEffectsOrPublicDiagnostics(): void
    {
        $effects = new class implements FlowStepExecutor {
            public int $calls=0;
            public function supports(FlowStepDefinition $step): bool { return true; }
            public function execute(FlowStepInvocation $invocation): StepResult { $this->calls++; return StepResult::completed(); }
        };
        foreach ([['rules'=>['id'=>'exists:private_users']],['syntax'=>'jq','expression'=>'.context.amount']] as $when) {
            $definition = new FlowDefinition('guard',[new FlowStepDefinition('effect','Effect',metadata:['when'=>$when])]);
            $run=new FlowRun('guard-run',$definition);
            $state=(new FlowRunner())->run($definition,$run->initialState(),new ArrayFlowContext(['amount'=>42]),[new GuardedStepExecutor($effects)]);
            self::assertSame(FlowStepStatus::FAILED,$state->steps['effect']->status);
            self::assertStringNotContainsString('private_users',$state->steps['effect']->error ?? '');
        }
        self::assertSame(0,$effects->calls);
    }
}
