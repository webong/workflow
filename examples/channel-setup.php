<?php

declare(strict_types=1);

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\Services\InMemoryFlowStateStore;
use Webong\WorkFlow\Services\RunScopedFlowStateStore;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

require dirname(__DIR__).'/vendor/autoload.php';

$definition = new FlowDefinition('channel_setup', [
    new FlowStepDefinition('authorize', 'Authorize provider access'),
    new FlowStepDefinition('webhook', 'Wait for provider webhook', dependsOn: ['authorize']),
    new FlowStepDefinition('verify', 'Verify the connection', dependsOn: ['webhook']),
], version: 1);

// These outcomes simulate a provider. A host supplies its actual API calls.
$executor = new class implements FlowStepExecutor {
    public function supports(FlowStepDefinition $step): bool
    {
        return in_array($step->id, ['authorize', 'webhook', 'verify'], true);
    }

    public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
    {
        return match ($step->id) {
            'authorize' => StepResult::completed('Provider access granted'),
            'webhook' => StepResult::deferred('Waiting for provider confirmation'),
            'verify' => $context->get('verification_ready') === true
                ? StepResult::completed('Channel connected')
                : StepResult::failed('Check the provider settings before starting a new setup run.'),
            default => throw new InvalidArgumentException('Unknown step'),
        };
    }
};

$run = new FlowRun('channel-42-setup-1', $definition);
$store = new RunScopedFlowStateStore(new InMemoryFlowStateStore(), $run->id);
$runner = new FlowRunner();
$context = new ArrayFlowContext(['channel_id' => '42', 'verification_ready' => ! in_array('--fail-verification', $argv ?? [], true)]);
$state = $runner->run($definition, $run->initialState(), $context, [$executor]);
$store->put($definition->key, $state);
printf("Started %s: %s; webhook: %s\n", $run->id, $state->status->value, $state->steps['webhook']->status->value);

$saved = $store->get($definition->key) ?? throw new RuntimeException('Run not found');
$state = $runner->run($definition, $saved, $context, [$executor]);
printf("Resume while waiting: webhook attempts = %d\n", $state->steps['webhook']->attempts);

$state = $store->mutate($definition->key, static function (?FlowState $current) use ($definition, $run): FlowState {
    return (new FlowStateTransition())->complete(
        $definition,
        $current ?? throw new RuntimeException('Run not found'),
        new FlowDeferredCompletion('channel_setup', 'webhook', 'provider-event-42', StepResult::completed(), $run->id, 1),
    );
});
printf("Webhook received: %s\n", $state->status->value);

$pinnedRun = $state->run ?? throw new RuntimeException('Run identity not found');
$state = $runner->run($pinnedRun->definition, $state, $context, [$executor]);
$store->put($definition->key, $state);
printf("After resume: %s; verify: %s\n", $state->status->value, $state->steps['verify']->status->value);
if ($state->status === FlowStatus::BLOCKED) {
    printf("Operator action: %s\n", $state->steps['verify']->error);
}
