<?php

declare(strict_types=1);

use Webong\WorkFlow\App\FlowRpcApplication;
use Webong\WorkFlow\App\QueueRpcRuntime;
use Webong\WorkFlow\Services\FlowExecutionDispatcher;
use Webong\WorkFlow\Services\FlowRunner;
use Webong\WorkFlow\Services\InlineFlowExecutionDriver;

require_once dirname(__DIR__).'/vendor/autoload.php';
$path = getenv('WORKFLOW_RUNTIME_BOOTSTRAP') ?: __DIR__.'/runtime.example.php';
$runtime = require $path;
$configuredCapabilities = getenv('WORKFLOW_CAPABILITIES');
$grantedCapabilities = is_string($configuredCapabilities) && trim($configuredCapabilities) !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $configuredCapabilities)), static fn (string $value): bool => $value !== ''))
    : null;


return new FlowRpcApplication(
    definitions: $runtime['definitions'],
    stores: $runtime['stores'],
    execution: new FlowExecutionDispatcher([
        new InlineFlowExecutionDriver(new FlowRunner(), $runtime['executors']),
    ]),
    grantedCapabilities: $grantedCapabilities,
    queue: $runtime['queue'] !== null ? new QueueRpcRuntime($runtime['queue']) : null,
);
