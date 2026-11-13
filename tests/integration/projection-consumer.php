<?php

declare(strict_types=1);

use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

[$script, $consumer, $case] = $argv;
require $consumer . '/vendor/autoload.php';
function ensure(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}
$package = $consumer . '/vendor/webong/workflow';
$full = $case === 'full';
$laravel = $full || in_array($case, ['laravel', 'combined'], true);
$temporal = $full || in_array($case, ['temporal', 'combined'], true);
$ows = $full || in_array($case, ['open-workflow', 'combined'], true);
$appSurface = $full || in_array($case, ['app', 'combined'], true);
foreach (['LICENSE', 'README.md', 'bin/workflow', 'app/src/FlowAuthoringCli.php', 'ext/Laravel/WorkFlowServiceProvider.php'] as $path) {
    ensure(is_file($package . '/' . $path), "$case: missing advertised/core file $path");
}
foreach (['ext/Laravel/LaravelFlowStateStoreFactory.php' => $laravel, 'ext/Temporal/TemporalFlowInput.php' => $temporal, 'ext/OpenWorkflow/OpenWorkflow.php' => $ows, 'app/src/FlowRpcApplication.php' => $appSurface, 'mod/typephp/composer.json' => $full] as $path => $expected) {
    ensure(is_file($package . '/' . $path) === $expected, "$case: incorrect projected presence for $path");
}
ensure(class_exists(Webong\WorkFlow\App\FlowAuthoringCli::class), 'Advertised CLI class unavailable');
ensure(class_exists(Webong\WorkFlow\Temporal\TemporalFlowInput::class) === $temporal, 'Temporal autoload mismatch');
ensure(class_exists(Webong\WorkFlow\OpenWorkflow\OpenWorkflow::class) === $ows, 'OWS autoload mismatch');
ensure(class_exists(Webong\WorkFlow\App\FlowRpcApplication::class) === $appSurface, 'App autoload mismatch');

$executor = new class implements FlowStepExecutor {
    public function supports(FlowStepDefinition $step): bool { return true; }
    public function execute(FlowStepInvocation $invocation): StepResult { return StepResult::completed(output: StepOutput::from(42)); }
};
$definition = new FlowDefinition('consumer', [new FlowStepDefinition('first', 'First')]);
$run = new FlowRun('consumer-run', $definition);
$finished = (new FlowRunner())->run($definition, $run->initialState(), new ArrayFlowContext(), [$executor]);
ensure($finished->status === FlowStatus::COMPLETED && $finished->steps['first']->output?->value === 42, 'Installed core cannot execute a flow');

if ($temporal) {
    $handler = new Webong\WorkFlow\Temporal\TemporalFlowActivityHandler([$executor]);
    $result = $handler->execute(['definition' => $definition->toArray(), 'step_id' => 'first']);
    ensure($result['status'] === 'completed' && $result['output'] === 42, 'Temporal SDK-neutral activity closure failed');
}
if ($ows) {
    $adapter = new Webong\WorkFlow\OpenWorkflow\OpenWorkflow();
    $report = $adapter->registerOwsDefinition(json_encode([
        'version' => '1.0.3', 'document' => ['namespace' => 'com.example', 'name' => 'projection', 'version' => '1.0.0'],
        'do' => [['first' => ['set' => ['answer' => 42]]]],
    ], JSON_THROW_ON_ERROR));
    ensure($report->valid && $report->unsupported === [] && $report->flowDefinition !== null, 'Projected OWS registration failed');
    $owsRun = new FlowRun('ows-run', $report->flowDefinition);
    $owsState = (new FlowRunner())->run($report->flowDefinition, $owsRun->initialState(), new Webong\WorkFlow\OpenWorkflow\Runtime\OwsWorkflowContext([]), [new Webong\WorkFlow\OpenWorkflow\Runtime\OwsSetStepExecutor()]);
    ensure($owsState->status === FlowStatus::COMPLETED && $owsState->steps['first']->output?->value === ['answer' => 42], 'Projected OWS execution failed');
    if ($temporal) {
        $owsActivity = new Webong\WorkFlow\Temporal\TemporalFlowActivityHandler([new Webong\WorkFlow\OpenWorkflow\Runtime\OwsSetStepExecutor()]);
        $activityResult = $owsActivity->execute(['definition' => $report->flowDefinition->toArray(), 'step_id' => 'first', 'state' => $owsRun->initialState()->toArray()]);
        ensure($activityResult['status'] === 'completed' && $activityResult['output'] === ['answer' => 42], 'Combined Temporal/OWS context restoration failed');
    }
    ensure(is_file($package . '/ext/OpenWorkflow/Validation/workflow-1.0.3.json'), 'OWS schema pruned');
    ensure(is_file($package . '/ext/OpenWorkflow/Conformance/features/set.feature'), 'OWS conformance resources pruned');
}
if ($appSurface) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(file_get_contents($package . '/app/schema/sqlite.sql'));
    Webong\WorkFlow\App\QueueScheduler::install($pdo);
    $queue = new Webong\WorkFlow\App\QueueScheduler($pdo);
    $queue->start($run); (new Webong\WorkFlow\App\QueueWorker($queue, [$executor]))->tick();
    ensure($queue->get($run->id)?->status === FlowStatus::COMPLETED, 'Projected App worker closure failed');
    $provider = new class($definition) implements Webong\WorkFlow\Contracts\FlowDefinitionProvider {
        public function __construct(private FlowDefinition $definition) {}
        public function definition(string $flowKey): FlowDefinition { return $this->definition; }
    };
    $facade = new Webong\WorkFlow\App\FlowRpcApplication($provider, new Webong\WorkFlow\App\PdoSqliteFlowStateStoreFactory($pdo, new Webong\WorkFlow\Services\DefaultFlowStateSerializer()), new Webong\WorkFlow\Services\FlowExecutionDispatcher([]), queue: new Webong\WorkFlow\App\QueueRpcRuntime($queue));
    $state = $facade->invoke('flow.start', ['flow_key' => 'consumer', 'run_id' => 'rpc-run', 'subject' => ['type' => 'test', 'id' => '42'], 'driver' => 'queue']);
    ensure($state['state']['metadata']['execution_driver'] === 'queue', 'Projected App RPC closure failed without optional OWS');
}

if (class_exists(Illuminate\Foundation\Application::class)) {
    foreach (['bootstrap/cache', 'config', 'database'] as $path) { @mkdir($consumer . '/' . $path, 0777, true); }
    $manifest = new Illuminate\Foundation\PackageManifest(new Illuminate\Filesystem\Filesystem(), $consumer, $consumer . '/bootstrap/cache/packages.php');
    $manifest->build();
    $provider = Webong\WorkFlow\Laravel\WorkFlowServiceProvider::class;
    ensure(in_array($provider, $manifest->providers(), true), 'Existing Laravel discovery name changed or disappeared');
    $application = new Illuminate\Foundation\Application($consumer);
    $application->instance('config', new Illuminate\Config\Repository([
        'app' => ['providers' => []],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
    ]));
    Illuminate\Support\Facades\Facade::setFacadeApplication($application);
    $application->register(Illuminate\Database\DatabaseServiceProvider::class);
    foreach ($manifest->providers() as $discovered) { $application->register($discovered); }
    $application->boot();
    ensure($application->bound(FlowStateStoreFactory::class) === $laravel, 'Discovery advertised a missing store or lost the full integration');
    ensure($application->bound(Webong\WorkFlow\OpenWorkflow\OpenWorkflow::class) === ($laravel && $ows), 'Laravel exposed missing OWS integration');
    $published = Illuminate\Support\ServiceProvider::pathsToPublish($provider);
    ensure(($published !== []) === $laravel, 'Discovery exposed pruned resources or lost original publication ownership');
    foreach (array_keys($published) as $path) { ensure(is_file($path), 'Provider publishes a deleted resource'); }
    if ($laravel) {
        foreach (glob($package . '/ext/Laravel/database/migrations/*.php') as $path) { (require $path)->up(); }
        $store = $application->make(FlowStateStoreFactory::class)->for(new FlowStateSubject('projection-order', '42'));
        $store->put('consumer', $finished);
        ensure($store->get('consumer')?->status === FlowStatus::COMPLETED, 'Projected Laravel database store failed');
    }
}
echo "PASS: $case autoload, execution, optional closures and Laravel discovery\n";
