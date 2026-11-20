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
ensure(! is_dir($package . '/app'), 'Removed app directory is still shipped');
ensure(! class_exists('Webong\\WorkFlow\\App\\FlowRpcApplication'), 'Removed App namespace is still available');
ensure(! class_exists('Webong\\WorkFlow\\App\\FlowAuthoringCli'), 'Removed CLI namespace alias is still available');
$laravel = in_array($case, ['laravel', 'combined'], true);
$temporal = in_array($case, ['temporal', 'combined'], true);
$ows = in_array($case, ['open-workflow', 'combined'], true);
$example = $full;
foreach (['LICENSE', 'README.md', 'bin/workflow', 'src/Authoring/FlowAuthoringCli.php'] as $path) {
    ensure(is_file($package . '/' . $path), "$case: missing advertised/core file $path");
}
ensure(! is_dir($package . '/pkg'), 'Core archive contains adapter payloads');
// Metadata survives every selection, while unselected directory contents do not.
foreach (['composer.surface.json', 'src/composer.json', 'types/composer.json', 'docs/composer.json'] as $descriptor) {
    ensure(is_file($package . '/' . $descriptor), 'Projection lost grouping metadata: ' . $descriptor);
}
$hasTypes = ! in_array($case, ['runtime', 'guides'], true);
$hasGuides = ! in_array($case, ['runtime', 'types'], true);
ensure(is_file($package . '/types/work-flow.d.ts') === $hasTypes, 'Incorrect types group payload');
ensure(is_file($package . '/docs/php-library.md') === $hasGuides, 'Incorrect guides group payload');

foreach (['workflow-laravel' => $laravel, 'workflow-temporal' => $temporal, 'workflow-open-workflow' => $ows, 'workflow-jq' => $ows] as $name => $expected) {
    $adapterRoot = $consumer . '/vendor/webong/' . $name;
    ensure(is_dir($adapterRoot) === $expected, 'Incorrect independent package presence: ' . $name);
    if ($expected) {
        ensure(! is_link($adapterRoot) && is_file($adapterRoot . '/LICENSE'), 'Adapter is not an independent licensed dist: ' . $name);
    }
}
ensure(is_file($package . '/examples/standalone/src/FlowRpcApplication.php') === $example, 'Incorrect example payload');
if ($case === 'core-minimal') {
    foreach (['illuminate/support', 'psr/http-message', 'wikimedia/zest-jq', 'temporal/sdk'] as $dependency) {
        ensure(! Composer\InstalledVersions::isInstalled($dependency), 'Core pulled adapter dependency: ' . $dependency);
    }
}
ensure(class_exists(Webong\WorkFlow\Laravel\WorkFlowServiceProvider::class) === $laravel, 'Core still exposes a Laravel discovery stub');
if ($laravel) {
    ensure(is_file($consumer . '/vendor/webong/workflow-laravel/resources/boost/skills/work-flow-development/SKILL.md'), 'Laravel package lost Boost resources');
}
ensure(class_exists(Webong\WorkFlow\Authoring\FlowAuthoringCli::class), 'Advertised CLI class unavailable');
ensure(class_exists(Webong\WorkFlow\Temporal\TemporalFlowInput::class) === $temporal, 'Temporal autoload mismatch');
ensure(class_exists(Webong\WorkFlow\OpenWorkflow\OpenWorkflow::class) === $ows, 'OWS autoload mismatch');
ensure(class_exists(Webong\WorkFlow\Examples\Standalone\FlowRpcApplication::class) === $example, 'Standalone example autoload mismatch');

$executor = new class implements FlowStepExecutor {
    public function supports(FlowStepDefinition $step): bool { return true; }
    public function execute(FlowStepInvocation $invocation): StepResult { return StepResult::completed(output: StepOutput::from(42)); }
};
$definition = new FlowDefinition('consumer', [new FlowStepDefinition('first', 'First')]);
$run = new FlowRun('consumer-run', $definition);
$finished = (new FlowRunner())->run($definition, $run->initialState(), new ArrayFlowContext(), [$executor]);
ensure($finished->status === FlowStatus::COMPLETED && $finished->steps['first']->output?->value === 42, 'Installed core cannot execute a flow');

if ($temporal) {
    ensure(interface_exists(Webong\WorkFlow\Temporal\TemporalFlowActivityInterface::class), 'Real Temporal SDK activity interface cannot load');
    $attributes = (new ReflectionClass(Webong\WorkFlow\Temporal\TemporalFlowWorkflow::class))->getAttributes(Temporal\Workflow\WorkflowInterface::class);
    ensure(count($attributes) === 1 && $attributes[0]->newInstance() instanceof Temporal\Workflow\WorkflowInterface, 'Temporal SDK workflow metadata cannot load');
    $handler = new Webong\WorkFlow\Temporal\TemporalFlowActivityHandler([$executor]);
    $result = $handler->execute(['definition' => $definition->toArray(), 'step_id' => 'first']);
    ensure($result['status'] === 'completed' && $result['output'] === 42, 'Temporal SDK-neutral activity closure failed');
}
if ($ows) {
    $yaml = Webong\WorkFlow\OpenWorkflow\ValueObjects\OwsDocument::fromYaml("version: '1.0.3'\ndocument: {namespace: com.example, name: yaml, version: '1.0.0'}\ndo: []\n");
    ensure($yaml->all()['version'] === '1.0.3', 'Declared YAML dependency is unavailable');
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
    ensure(is_file($consumer . '/vendor/webong/workflow-open-workflow/Validation/workflow-1.0.3.json'), 'OWS schema pruned');
    ensure(is_file($consumer . '/vendor/webong/workflow-open-workflow/Conformance/features/set.feature'), 'OWS conformance resources pruned');
}
if ($example) {
    // Test an installed dependency, where the example has no nested vendor/.
    $location = 'examples/standalone';
    $environment = array_merge(getenv(), ['WORKFLOW_DB_DRIVER' => 'sqlite', 'WORKFLOW_SQLITE_PATH' => $consumer . '/entrypoint.sqlite', 'WORKFLOW_QUEUE_ENABLED' => 'true']);
    foreach ([
        [PHP_BINARY, $package . '/' . $location . '/queue-worker.php', '--install'],
        [PHP_BINARY, $package . '/' . $location . '/queue-worker.php', '--once'],
        [PHP_BINARY, '-r', '$app = require $argv[1]; if (! $app instanceof Webong\\WorkFlow\\Examples\\Standalone\\FlowRpcApplication) { exit(1); }', $package . '/' . $location . '/bootstrap.example.php'],
    ] as $args) {
        $pipes = [];
        $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $consumer, $environment);
        ensure(is_resource($process), 'Cannot start installed standalone entrypoint');
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        ensure(proc_close($process) === 0, 'Installed standalone entrypoint failed: ' . $stdout . $stderr);
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(file_get_contents($package . '/examples/standalone/schema/sqlite.sql'));
    Webong\WorkFlow\Examples\Standalone\QueueScheduler::install($pdo);
    $queue = new Webong\WorkFlow\Examples\Standalone\QueueScheduler($pdo);
    $queue->start($run); (new Webong\WorkFlow\Examples\Standalone\QueueWorker($queue, [$executor]))->tick();
    ensure($queue->get($run->id)?->status === FlowStatus::COMPLETED, 'Standalone example worker closure failed');
    $provider = new class($definition) implements Webong\WorkFlow\Contracts\FlowDefinitionProvider {
        public function __construct(private FlowDefinition $definition) {}
        public function definition(string $flowKey): FlowDefinition { return $this->definition; }
    };
    $facade = new Webong\WorkFlow\Examples\Standalone\FlowRpcApplication($provider, new Webong\WorkFlow\Examples\Standalone\PdoSqliteFlowStateStoreFactory($pdo, new Webong\WorkFlow\Services\DefaultFlowStateSerializer()), new Webong\WorkFlow\Services\FlowExecutionDispatcher([]), queue: new Webong\WorkFlow\Examples\Standalone\QueueRpcRuntime($queue));
    $state = $facade->invoke('flow.start', ['flow_key' => 'consumer', 'run_id' => 'rpc-run', 'subject' => ['type' => 'test', 'id' => '42'], 'driver' => 'queue']);
    ensure($state['state']['metadata']['execution_driver'] === 'queue', 'Standalone example RPC closure failed without optional OWS');
}

if (class_exists(Illuminate\Foundation\Application::class)) {
    foreach (['bootstrap/cache', 'config', 'database'] as $path) { @mkdir($consumer . '/' . $path, 0777, true); }
    $manifest = new Illuminate\Foundation\PackageManifest(new Illuminate\Filesystem\Filesystem(), $consumer, $consumer . '/bootstrap/cache/packages.php');
    $manifest->build();
    $provider = Webong\WorkFlow\Laravel\WorkFlowServiceProvider::class;
    ensure(in_array($provider, $manifest->providers(), true) === $laravel, 'Laravel discovery is not owned by the adapter');
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
        foreach (glob($consumer . '/vendor/webong/workflow-laravel/database/migrations/*.php') as $path) { (require $path)->up(); }
        $store = $application->make(FlowStateStoreFactory::class)->for(new FlowStateSubject('projection-order', '42'));
        $store->put('consumer', $finished);
        ensure($store->get('consumer')?->status === FlowStatus::COMPLETED, 'Projected Laravel database store failed');
    }
}
echo "PASS: $case autoload, execution, optional closures and Laravel discovery\n";

// A core-only installation must reject unchecked guards without pulling in optional dependencies.
if ($case === 'core-minimal') {
    $report = (new \Webong\WorkFlow\Services\FlowDefinitionValidator())->validate([
        'key' => 'guard', 'steps' => [['id' => 'a', 'metadata' => ['when' => ['rules' => []]]]],
    ]);
    ensure(! $report->isValid() && $report->errors()[0]->code === 'condition_driver_unavailable', 'Core-only install silently accepted unchecked conditions');
}
