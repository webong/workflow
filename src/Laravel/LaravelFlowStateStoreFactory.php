<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Webong\WorkFlow\Contracts\AtomicFlowStateStore;
use Webong\WorkFlow\Contracts\FlowStateSerializer;
use Webong\WorkFlow\Contracts\FlowStateStoreFactory;
use Webong\WorkFlow\Laravel\Models\WorkflowStateRecord;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;

final class LaravelFlowStateStoreFactory implements FlowStateStoreFactory
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        private readonly FlowStateSerializer $serializer,
        private readonly string $driver,
        private readonly array $configuration = [],
        private readonly ?Connection $connection = null,
        private readonly ?Repository $cache = null,
        private readonly ?LockProvider $locks = null,
    ) {
    }

    public function for(FlowStateSubject $subject): AtomicFlowStateStore
    {
        return match ($this->driver) {
            'database' => $this->databaseStore($subject),
            'redis' => $this->redisStore($subject),
            default => throw new InvalidArgumentException("Unsupported WorkFlow state store driver [{$this->driver}]."),
        };
    }

    private function databaseStore(FlowStateSubject $subject): DatabaseFlowStateStore
    {
        if ($this->connection === null) {
            throw new InvalidArgumentException('The WorkFlow database store requires a database connection.');
        }

        return new DatabaseFlowStateStore($subject, $this->serializer, $this->connection, new WorkflowStateRecord());
    }

    private function redisStore(FlowStateSubject $subject): RedisFlowStateStore
    {
        if ($this->cache === null || $this->locks === null) {
            throw new InvalidArgumentException('The WorkFlow Redis store requires a cache repository with lock support.');
        }

        $redis = is_array($this->configuration['redis'] ?? null) ? $this->configuration['redis'] : [];

        return new RedisFlowStateStore(
            subject: $subject,
            serializer: $this->serializer,
            cache: $this->cache,
            locks: $this->locks,
            prefix: is_string($redis['prefix'] ?? null) ? $redis['prefix'] : 'work-flow',
            ttlSeconds: is_int($redis['ttl'] ?? null) ? $redis['ttl'] : null,
            lockSeconds: is_int($redis['lock_seconds'] ?? null) ? $redis['lock_seconds'] : 30,
            lockWaitSeconds: is_int($redis['lock_wait_seconds'] ?? null) ? $redis['lock_wait_seconds'] : 5,
        );
    }
}
