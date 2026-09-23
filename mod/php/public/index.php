<?php

declare(strict_types=1);

use Webong\WorkFlow\Mod\FlowRpcApplication;
use Webong\WorkFlow\Mod\RpcFailure;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

header('Content-Type: application/json');

try {
    $body = file_get_contents('php://input');
    $request = json_decode($body === false ? '' : $body, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($request) || ! is_string($request['method'] ?? null) || ! is_array($request['params'] ?? null)) {
        throw new RpcFailure(-32602, 'Invalid params');
    }

    $bootstrap = getenv('WORKFLOW_BOOTSTRAP');
    if (! is_string($bootstrap) || $bootstrap === '') {
        throw new RuntimeException('WORKFLOW_BOOTSTRAP is not configured');
    }

    $application = require $bootstrap;
    if (! $application instanceof FlowRpcApplication) {
        throw new RuntimeException('WORKFLOW_BOOTSTRAP must return a FlowRpcApplication');
    }

    $response = ['result' => $application->invoke($request['method'], $request['params'])];
} catch (RpcFailure $exception) {
    $response = ['error' => ['code' => $exception->rpcCode, 'message' => $exception->getMessage()]];
} catch (InvalidArgumentException $exception) {
    $response = ['error' => ['code' => -32602, 'message' => 'Invalid params']];
} catch (Throwable $exception) {
    error_log((string) $exception);
    $response = ['error' => ['code' => -32603, 'message' => 'Internal error']];
}

echo json_encode($response, JSON_THROW_ON_ERROR);
