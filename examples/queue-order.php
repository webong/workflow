<?php

declare(strict_types=1);

// A runnable, two-process example. Only host-registered executors run here.
require dirname(__DIR__) . '/vendor/autoload.php';

use Webong\WorkFlow\App\QueueScheduler;
use Webong\WorkFlow\App\QueueWorker;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowRun;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepOutput;

$command = $argv[1] ?? 'status';
$path = $argv[2] ?? sys_get_temp_dir() . '/workflow-queue-example.sqlite';
$pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$queue = new QueueScheduler($pdo);
if ($command === 'init') {
    QueueScheduler::install($pdo, 2);
    $queue->start(new FlowRun('order-42', new FlowDefinition('fulfil-order', [
        new FlowStepDefinition('inventory', 'Reserve inventory'),
        new FlowStepDefinition('payment', 'Verify payment'),
        new FlowStepDefinition('ship', 'Ship order', dependsOn: ['inventory', 'payment']),
    ])));
} elseif ($command === 'work') {
    $worker = new QueueWorker($queue, [new class implements FlowStepExecutor {
        public function supports(FlowStepDefinition $step): bool { return true; }
        public function execute(FlowStepInvocation $invocation): StepResult {
            fwrite(STDERR, sprintf("%d starting %s\n", getmypid(), $invocation->step->id));
            // An external provider would deduplicate using $invocation->context->all()['_queue']['idempotency_key'].
            sleep(1);
            fwrite(STDERR, sprintf("%d finished %s\n", getmypid(), $invocation->step->id));
            return StepResult::completed(output: StepOutput::from(['done' => $invocation->step->id]));
        }
    }]);
    $running = true;
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
        pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
    }
    while ($running) {
        if ($worker->tick(30)) { continue; }
        $state = $queue->get('order-42');
        if ($state === null || in_array($state->status, [FlowStatus::COMPLETED, FlowStatus::BLOCKED, FlowStatus::CANCELLED], true)) { break; }
        // A production supervisor can wake on notifications; this demo caps its idle wait at one second.
        sleep(1);
    }
} elseif ($command === 'cancel') {
    $queue->cancelRun('order-42');
} elseif ($command !== 'status') {
    fwrite(STDERR, "Usage: php examples/queue-order.php init|work|status|cancel [sqlite-path]\n");
    exit(2);
}
echo json_encode($queue->get('order-42')?->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
