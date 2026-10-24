<?php

declare(strict_types=1);

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowDefinitionProvider;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\App\FlowRpcApplication;
use Webong\WorkFlow\App\PdoMySqlFlowStateStoreFactory;
use Webong\WorkFlow\App\PdoPostgresFlowStateStoreFactory;
use Webong\WorkFlow\App\PdoSqliteFlowStateStoreFactory;
use Webong\WorkFlow\Services\DefaultFlowStateSerializer;
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\InlineFlowExecutionDriver;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

require_once dirname(__DIR__).'/vendor/autoload.php';

$driver = getenv('WORKFLOW_DB_DRIVER') ?: 'postgres';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

if ($driver === 'postgres') {
    $dsn = getenv('WORKFLOW_POSTGRES_DSN');
    if (! is_string($dsn) || $dsn === '') {
        throw new RuntimeException('WORKFLOW_POSTGRES_DSN is required');
    }

    $connection = new PDO(
        $dsn,
        getenv('WORKFLOW_POSTGRES_USER') ?: null,
        getenv('WORKFLOW_POSTGRES_PASSWORD') ?: null,
        $options,
    );
} elseif ($driver === 'mysql') {
    $dsn = getenv('WORKFLOW_MYSQL_DSN');
    if (! is_string($dsn) || $dsn === '') {
        throw new RuntimeException('WORKFLOW_MYSQL_DSN is required');
    }

    $connection = new PDO(
        $dsn,
        getenv('WORKFLOW_MYSQL_USER') ?: null,
        getenv('WORKFLOW_MYSQL_PASSWORD') ?: null,
        $options,
    );
} elseif ($driver === 'sqlite') {
    $path = getenv('WORKFLOW_SQLITE_PATH');
    if (! is_string($path) || $path === '') {
        throw new RuntimeException('WORKFLOW_SQLITE_PATH is required');
    }

    $connection = new PDO('sqlite:'.$path, null, null, $options);
    // Wait instead of failing when another request holds the write lock.
    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec('PRAGMA journal_mode = WAL');
} else {
    throw new RuntimeException(sprintf('Unsupported WORKFLOW_DB_DRIVER "%s"', $driver));
}

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

$serializer = new DefaultFlowStateSerializer();

$factory = match ($driver) {
    'postgres' => new PdoPostgresFlowStateStoreFactory($connection, $serializer),
    'mysql' => new PdoMySqlFlowStateStoreFactory($connection, $serializer),
    'sqlite' => new PdoSqliteFlowStateStoreFactory($connection, $serializer),
};

return new FlowRpcApplication(
    definitions: $definitions,
    stores: $factory,
    execution: new FlowExecutionDispatcher([
        new InlineFlowExecutionDriver(new FlowRunner(), [$executor]),
    ]),
);
