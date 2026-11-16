<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use LogicException;
use Webong\WorkFlow\Contracts\FlowStateSerializer;
use Webong\WorkFlow\Contracts\ForgettableFlowStateStore;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;

final class RedisFlowStateStore implements ForgettableFlowStateStore
{
    public function __construct(
        private readonly FlowStateSubject $subject,
        private readonly FlowStateSerializer $serializer,
        private readonly Repository $cache,
        private readonly LockProvider $locks,
        private readonly string $prefix = 'work-flow',
        private readonly ?int $ttlSeconds = null,
        private readonly int $lockSeconds = 30,
        private readonly int $lockWaitSeconds = 5,
    ) {
    }

    public function get(string $flowKey): ?FlowState
    {
        $payload = $this->payload($flowKey);

        if ($payload === null) {
            return null;
        }

        return $this->serializer->deserialize($payload['state']);
    }

    public function put(string $flowKey, FlowState $state): void
    {
        $this->mutate($flowKey, static fn (?FlowState $current): FlowState => $state);
    }

    public function mutate(string $flowKey, Closure $transition): FlowState
    {
        $result = $this->locks->lock($this->lockKey($flowKey), $this->lockSeconds)->block(
            $this->lockWaitSeconds,
            function () use ($flowKey, $transition): FlowState {
                $payload = $this->payload($flowKey);
                $current = $payload === null ? null : $this->serializer->deserialize($payload['state']);
                $next = $transition($current);
                $payload = [
                    'state' => $this->serializer->serialize($next),
                    'schema_version' => FlowState::SCHEMA_VERSION,
                    'lock_version' => ($payload['lock_version'] ?? 0) + 1,
                ];

                if ($this->ttlSeconds === null) {
                    $this->cache->forever($this->key($flowKey), $payload);
                } else {
                    $this->cache->put($this->key($flowKey), $payload, $this->ttlSeconds);
                }

                return $next;
            },
        );

        if (! $result instanceof FlowState) {
            throw new LogicException('The WorkFlow Redis lock callback did not return a flow state.');
        }

        return $result;
    }

    public function forget(string $flowKey): void
    {
        $result = $this->locks->lock($this->lockKey($flowKey), $this->lockSeconds)->block(
            $this->lockWaitSeconds,
            fn (): bool => $this->cache->forget($this->key($flowKey)),
        );

        if (! is_bool($result)) {
            throw new LogicException('The WorkFlow Redis lock callback did not return a deletion result.');
        }
    }

    private function key(string $flowKey): string
    {
        return sprintf(
            '%s:v1:%s:flow:%s',
            trim($this->prefix, ':'),
            hash('sha256', $this->subject->type . "\0" . $this->subject->id),
            hash('sha256', $flowKey),
        );
    }

    private function lockKey(string $flowKey): string
    {
        return $this->key($flowKey) . ':lock';
    }

    /** @return array{state: array<string, mixed>, lock_version: int}|null */
    private function payload(string $flowKey): ?array
    {
        $payload = $this->cache->get($this->key($flowKey));

        if ($payload === null) {
            return null;
        }

        if (! is_array($payload) || ! is_array($payload['state'] ?? null)) {
            throw new \UnexpectedValueException('The stored workflow state payload is malformed.');
        }

        return [
            'state' => $this->stateData($payload['state']),
            'lock_version' => is_int($payload['lock_version'] ?? null) ? $payload['lock_version'] : 0,
        ];
    }

    /**
     * @param array<string|int, mixed> $data
     * @return array<string, mixed>
     */
    private function stateData(array $data): array
    {
        $state = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $state[$key] = $value;
            }
        }

        return $state;
    }
}
