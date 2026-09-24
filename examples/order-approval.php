<?php

declare(strict_types=1);

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\Services\InMemoryFlowStateStore;
use Webong\WorkFlow\Services\RunScopedFlowStateStore;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

require dirname(__DIR__).'/vendor/autoload.php';

$definition = new FlowDefinition('order_approval', [
    new FlowStepDefinition('approve', 'Approve the order'),
    new FlowStepDefinition('notify', 'Notify the customer', dependsOn: ['approve']),
]);

$executor = new class implements FlowStepExecutor {
    public function supports(FlowStepDefinition $step): bool
    {
        return in_array($step->id, ['approve', 'notify'], true);
    }

    public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
    {
        return match ($step->id) {
            'approve' => StepResult::deferred('Waiting for approval'),
            'notify' => StepResult::completed('Customer notified'),
            default => throw new InvalidArgumentException("Unknown step: {$step->id}"),
        };
    }
};

$run = new FlowRun('approval-42-1', $definition);
$store = new RunScopedFlowStateStore(new InMemoryFlowStateStore(), $run->id);
$runner = new FlowRunner();
$context = new ArrayFlowContext(['order_id' => 'order-42']);

$state = $runner->run($definition, $run->initialState(), $context, [$executor]);
$store->put($definition->key, $state);
printf("After start: %s; approve: %s\n", $state->status->value, $state->steps['approve']->status->value);

$state = $store->mutate(
    $definition->key,
    static fn (?FlowState $current): FlowState => (new FlowStateTransition())->complete(
        $definition,
        $current ?? throw new RuntimeException('Flow state was not saved'),
        new FlowDeferredCompletion(
            flowKey: $definition->key,
            stepId: 'approve',
            idempotencyKey: 'approval-event-1',
            result: StepResult::completed('Order approved'),
            runId: $run->id,
            attempt: 1,
        ),
    ),
);
printf("After callback: %s; approve: %s\n", $state->status->value, $state->steps['approve']->status->value);

$state = $runner->run($definition, $state, $context, [$executor]);
$store->put($definition->key, $state);
printf("After resume: %s; notify: %s\n", $state->status->value, $state->steps['notify']->status->value);
