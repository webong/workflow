<?php

declare(strict_types=1);

require_once __DIR__.'/../php/autoload.php';

if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 3) {
    throw new RuntimeException('Generate the parity oracle in the PHP 8.3 container.');
}

$cases = [];
$record = static function (string $name, array|string $request) use (&$cases): array {
    $json = is_string($request) ? $request : json_encode(['protocol' => 1, ...$request], JSON_THROW_ON_ERROR);
    $response = workflow_native_dispatch($json);
    $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    $cases[] = ['name' => $name, 'request' => $json, 'response' => $decoded];

    return $decoded;
};

$definition = ['key' => 'approval', 'version' => 2, 'steps' => [
    ['id' => 'notify', 'depends_on' => ['approve']],
    ['id' => 'approve', 'retry_policy' => ['max_attempts' => 2]],
], 'metadata' => ['amount' => 1.0, 'unicode' => 'Àṣẹ']];
$start = $record('start', ['operation' => 'start', 'run_id' => 'run-1', 'definition' => $definition]);
$waiting = $record('advance', ['operation' => 'advance', 'state' => $start['state']]);
$record('deferred-is-not-reissued', ['operation' => 'advance', 'state' => $waiting['state']]);
$record('evaluate', ['operation' => 'evaluate', 'state' => $waiting['state']]);
$completion = [
    'flow_key' => 'approval', 'run_id' => 'run-1', 'step_id' => 'approve',
    'attempt' => 1, 'idempotency_key' => 'approved',
    'result' => ['status' => 'completed', 'metadata' => ['z' => 1.0, 'a' => 2]],
];
$approved = $record('complete', ['operation' => 'complete', 'state' => $waiting['state'], 'completion' => $completion]);
$record('duplicate', ['operation' => 'complete', 'state' => $approved['state'], 'completion' => $completion]);
$reordered = $completion;
$reordered['result']['metadata'] = ['a' => 2, 'z' => 1];
$record('canonical-duplicate', ['operation' => 'complete', 'state' => $approved['state'], 'completion' => $reordered]);
$conflict = $completion;
$conflict['result']['status'] = 'failed';
$record('conflicting-event', ['operation' => 'complete', 'state' => $approved['state'], 'completion' => $conflict]);
foreach (['run_id' => 'wrong', 'flow_key' => 'wrong', 'step_id' => 'wrong', 'attempt' => 2] as $key => $value) {
    $wrong = [...$completion, $key => $value];
    $record('wrong-'.$key, ['operation' => 'complete', 'state' => $waiting['state'], 'completion' => $wrong]);
}
$notifying = $record('dependent-work', ['operation' => 'advance', 'state' => $approved['state']]);
$finished = $record('finish', ['operation' => 'complete', 'state' => $notifying['state'], 'completion' => [
    ...$completion, 'step_id' => 'notify', 'idempotency_key' => 'notified',
]]);
$record('terminal-no-replay', ['operation' => 'advance', 'state' => $finished['state']]);
$cancelled = $record('cancel', ['operation' => 'cancel', 'state' => $waiting['state']]);
$record('cancelled-no-work', ['operation' => 'advance', 'state' => $cancelled['state']]);
$record('cancelled-no-completion', ['operation' => 'complete', 'state' => $cancelled['state'], 'completion' => $completion]);
$record('completed-no-cancel', ['operation' => 'cancel', 'state' => $finished['state']]);
$record('pinned-definition', ['operation' => 'evaluate', 'state' => $waiting['state'], 'definition' => [...$definition, 'version' => 3]]);
$record('second-run', ['operation' => 'start', 'run_id' => 'run-2', 'definition' => $definition]);
$record('empty-flow', ['operation' => 'start', 'run_id' => 'empty', 'definition' => ['key' => 'empty']]);
$record('cycle', ['operation' => 'start', 'run_id' => 'cycle', 'definition' => ['key' => 'cycle', 'steps' => [['id' => 'a', 'depends_on' => ['a']]]]]);
$failure = [...$completion, 'result' => ['status' => 'failed', 'error' => 'Provider unavailable', 'retriable' => false]];
$failed = $record('permanent-failure', ['operation' => 'complete', 'state' => $waiting['state'], 'completion' => $failure]);
$record('permanent-no-retry', ['operation' => 'advance', 'state' => $failed['state']]);
$failure['result']['retriable'] = true;
$retryable = $record('retryable-failure', ['operation' => 'complete', 'state' => $waiting['state'], 'completion' => $failure]);
// Use an already elapsed deadline so slow CI is not part of the oracle.
$retryable['state']['steps']['approve']['next_retry_at'] = 1;
$retry = $record('retry-attempt', ['operation' => 'advance', 'state' => $retryable['state']]);
$record('stale-attempt', ['operation' => 'complete', 'state' => $retry['state'], 'completion' => $completion]);
$failure['attempt'] = 2;
$failure['idempotency_key'] = 'failed-again';
$exhausted = $record('retry-limit', ['operation' => 'complete', 'state' => $retry['state'], 'completion' => $failure]);
$record('exhausted-no-retry', ['operation' => 'advance', 'state' => $exhausted['state']]);
$backoffDefinition = ['key' => 'backoff', 'steps' => [['id' => 'send', 'retry_policy' => ['max_attempts' => 3, 'backoff_seconds' => 3600]]]];
$backoff = $record('backoff-start', ['operation' => 'start', 'run_id' => 'backoff-1', 'definition' => $backoffDefinition]);
$backoff = $record('backoff-advance', ['operation' => 'advance', 'state' => $backoff['state']]);
$backoff = $record('backoff-deadline', ['operation' => 'complete', 'state' => $backoff['state'], 'completion' => [
    ...$backoff['work'][0], 'idempotency_key' => 'backoff-event', 'result' => ['status' => 'failed', 'retriable' => true],
]]);
$backoff['state']['steps']['send']['next_retry_at'] = 2147483647;
$record('backoff-no-early-work', ['operation' => 'advance', 'state' => $backoff['state']]);
foreach (['{', 'null', '[]', '{"protocol":2}', '{"protocol":1,"operation":"unknown"}'] as $i => $invalid) {
    $record('invalid-'.$i, $invalid);
}
$record('invalid-retry-type', ['operation' => 'complete', 'state' => $waiting['state'], 'completion' => [
    ...$completion, 'result' => ['status' => 'failed', 'retriable' => 'yes'],
]]);
$record('nul-byte', "{\0}");

$output = $argv[1] ?? '/build/php83-cases.json';
file_put_contents($output, json_encode(['php' => PHP_VERSION, 'cases' => $cases], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo count($cases).' parity cases exported from PHP '.PHP_VERSION.PHP_EOL;
