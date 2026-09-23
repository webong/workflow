<?php

declare(strict_types=1);

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowDefinitionProvider;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Mod\FlowRpcApplication;
use Webong\WorkFlow\Mod\PdoPostgresFlowStateStoreFactory;
use Webong\WorkFlow\Services\DefaultFlowStateSerializer;
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\InlineFlowExecutionDriver;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

$dsn = getenv('WORKFLOW_POSTGRES_DSN');
if (! is_string($dsn) || $dsn === '') {
    throw new RuntimeException('WORKFLOW_POSTGRES_DSN is required');
}

$connection = new PDO(
    $dsn,
    getenv('WORKFLOW_POSTGRES_USER') ?: null,
    getenv('WORKFLOW_POSTGRES_PASSWORD') ?: null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$definitions = new class implements FlowDefinitionProvider {
    public function definition(string $flowKey): FlowDefinition
    {
        if ($flowKey !== 'demo_approval') {
            throw new InvalidArgumentException('Unknown flow key');
        }

        return new FlowDefinition('demo_approval', [new FlowStepDefinition('approve', 'Approve the request')]);
    }
};

$executor = new class implements FlowStepExecutor {
    public function supports(FlowStepDefinition $step): bool
    {
        return $step->id === 'approve';
    }

    public function execute(FlowStepDefinition $step, FlowContext $context, StepState $previous): StepResult
    {
        return StepResult::deferred('Waiting for approval');
    }
};

return new FlowRpcApplication(
    definitions: $definitions,
    stores: new PdoPostgresFlowStateStoreFactory($connection, new DefaultFlowStateSerializer()),
    execution: new FlowExecutionDispatcher([
        new InlineFlowExecutionDriver(new FlowRunner(), [$executor]),
    ]),
);
