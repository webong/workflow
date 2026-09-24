<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Native;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Native\NativeFlow;

require_once __DIR__.'/../../mod/typephp/php/NativeFlow.php';
require_once __DIR__.'/../../mod/typephp/php/api.php';

final class NativeFlowTest extends TestCase
{
    public function test_host_can_complete_a_run_without_php_executors_or_a_store(): void
    {
        $start = $this->call(['operation' => 'start', 'run_id' => 'run-1', 'definition' => [
            'key' => 'approval', 'steps' => [
                ['id' => 'notify', 'depends_on' => ['approve']], ['id' => 'approve'],
            ],
        ]]);
        self::assertTrue($start['ok']);
        self::assertSame('run-1', $start['state']['run']['id']);
        $waiting = $this->call(['operation' => 'advance', 'state' => $start['state']]);
        self::assertSame([['flow_key' => 'approval', 'run_id' => 'run-1', 'step_id' => 'approve', 'attempt' => 1]], $waiting['work']);
        self::assertSame([], $this->call(['operation' => 'advance', 'state' => $waiting['state']])['work']);

        $completion = [...$waiting['work'][0], 'idempotency_key' => 'event-1', 'result' => ['status' => 'completed']];
        $approved = $this->call(['operation' => 'complete', 'state' => $waiting['state'], 'completion' => $completion]);
        self::assertSame($approved, $this->call(['operation' => 'complete', 'state' => $approved['state'], 'completion' => $completion]));
        $notifying = $this->call(['operation' => 'advance', 'state' => $approved['state']]);
        self::assertSame('notify', $notifying['work'][0]['step_id']);
        $finished = $this->call(['operation' => 'complete', 'state' => $notifying['state'], 'completion' => [
            ...$notifying['work'][0], 'idempotency_key' => 'event-2', 'result' => ['status' => 'skipped', 'message' => 'Not needed'],
        ]]);
        self::assertSame('completed', $finished['state']['status']);
        self::assertSame([], $this->call(['operation' => 'advance', 'state' => $finished['state']])['work']);
    }

    public function test_pinning_and_cancellation_are_core_rules(): void
    {
        $start = $this->call(['operation' => 'start', 'run_id' => 'run-1', 'definition' => ['key' => 'approval', 'steps' => [['id' => 'approve']]]]);
        $changed = $this->call(['operation' => 'evaluate', 'state' => $start['state'], 'definition' => ['key' => 'approval', 'version' => 2]]);
        self::assertFalse($changed['ok']);
        self::assertArrayNotHasKey('state', $changed);
        $cancelled = $this->call(['operation' => 'cancel', 'state' => $start['state']]);
        self::assertSame('cancelled', $cancelled['state']['status']);
        self::assertSame([], $this->call(['operation' => 'advance', 'state' => $cancelled['state']])['work']);
    }

    public function test_permanent_failure_prevents_new_work(): void
    {
        $start = $this->call(['operation' => 'start', 'run_id' => 'run-1', 'definition' => [
            'key' => 'approval', 'steps' => [['id' => 'approve', 'retry_policy' => ['max_attempts' => 3]]],
        ]]);
        $waiting = $this->call(['operation' => 'advance', 'state' => $start['state']]);
        $failed = $this->call(['operation' => 'complete', 'state' => $waiting['state'], 'completion' => [
            ...$waiting['work'][0], 'idempotency_key' => 'failed',
            'result' => ['status' => 'failed', 'error' => 'Declined', 'retriable' => false],
        ]]);
        self::assertTrue($failed['ok']);
        self::assertSame('blocked', $failed['state']['status']);
        self::assertSame([], $this->call(['operation' => 'advance', 'state' => $failed['state']])['work']);
    }

    public function test_malformed_completions_and_unknown_operations_do_not_mutate_state(): void
    {
        $start = $this->call(['operation' => 'start', 'run_id' => 'run-1', 'definition' => ['key' => 'approval', 'steps' => [['id' => 'approve']]]]);
        $waiting = $this->call(['operation' => 'advance', 'state' => $start['state']]);
        foreach ([
            ['status' => 'running'], ['status' => 'invalid'],
            ['status' => 'failed', 'retriable' => 'yes'],
            ['status' => 'completed', 'message' => 12],
            ['status' => 'completed', 'metadata' => 'bad'],
        ] as $result) {
            $response = $this->call(['operation' => 'complete', 'state' => $waiting['state'], 'completion' => [
                ...$waiting['work'][0], 'idempotency_key' => 'event', 'result' => $result,
            ]]);
            self::assertFalse($response['ok']);
            self::assertArrayNotHasKey('state', $response);
        }
        self::assertFalse($this->call(['operation' => 'unknown', 'state' => $waiting['state']])['ok']);
        self::assertSame($waiting['state'], $this->call(['operation' => 'evaluate', 'state' => $waiting['state']])['state']);
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_input_returns_a_non_leaking_error(string $request): void
    {
        $response = (new NativeFlow())->dispatch($request);
        self::assertSame('{"ok":false,"error":{"code":"invalid_request","message":"Invalid native workflow request."}}', $response);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRequests(): iterable
    {
        yield 'malformed' => ['{'];
        yield 'null' => ['null'];
        yield 'protocol' => ['{"protocol":2}'];
        yield 'operation' => ['{"protocol":1}'];
        yield 'oversized' => [str_repeat(' ', 1048577)];
        yield 'missing run' => ['{"protocol":1,"operation":"start","definition":{"key":"x"}}'];
        yield 'missing definition' => ['{"protocol":1,"operation":"start","run_id":"x"}'];
        yield 'list definition' => ['{"protocol":1,"operation":"start","run_id":"x","definition":[1]}'];
        yield 'legacy state' => ['{"protocol":1,"operation":"advance","state":{"status":"pending"}}'];
        yield 'cycle' => ['{"protocol":1,"operation":"start","run_id":"x","definition":{"key":"x","steps":[{"id":"x","depends_on":["x"]}]}}'];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function call(array $request): array
    {
        return json_decode(\workflow_native_dispatch(json_encode(['protocol' => 1, ...$request], JSON_THROW_ON_ERROR)), true, flags: JSON_THROW_ON_ERROR);
    }
}
