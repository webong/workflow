<?php

declare(strict_types=1);

use Webong\WorkFlow\App\QueueScheduler;
use Webong\WorkFlow\App\QueueWorker;
use Webong\WorkFlow\Services\FlowStepExecution;

require_once dirname(__DIR__).'/vendor/autoload.php';
$path = getenv('WORKFLOW_RUNTIME_BOOTSTRAP') ?: __DIR__.'/runtime.example.php';
$runtime = require $path;
$queue = $runtime['queue'] ?? null;
if (! $queue instanceof QueueScheduler) {
    throw new RuntimeException('Queue runtime is not enabled in the trusted bootstrap.');
}
$mode = $argv[1] ?? '--work';
if ($mode === '--install') {
    $connection = $runtime['queue_connection'] ?? $runtime['connection'] ?? null;
    if (! $connection instanceof PDO) { throw new RuntimeException('Queue installation requires a PDO connection.'); }
    $capacity = filter_var(getenv('WORKFLOW_QUEUE_CONCURRENCY') ?: '10', FILTER_VALIDATE_INT);
    if ($capacity === false || $capacity < 1) { throw new InvalidArgumentException('Invalid queue concurrency.'); }
    QueueScheduler::install($connection, $capacity);
    exit(0);
}
if (! in_array($mode, ['--work', '--once'], true)) {
    throw new InvalidArgumentException('Expected --install, --once, or --work.');
}
$lease = filter_var(getenv('WORKFLOW_QUEUE_LEASE_SECONDS') ?: '300', FILTER_VALIDATE_INT);
if ($lease === false || $lease < 1) { throw new InvalidArgumentException('Invalid queue lease duration.'); }
$worker = new QueueWorker($queue, $runtime['executors'], $runtime['execution'] ?? new FlowStepExecution());
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}
do {
    $worked = $worker->tick($lease);
    if ($mode === '--once' || ! $running) { break; }
    if (! $worked) {
        sleep(max(1, min(5, ($queue->nextWakeup() ?? time() + 5) - time())));
    }
} while ($running);
