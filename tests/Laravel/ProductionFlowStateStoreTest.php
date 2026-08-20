<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Laravel;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Redis\RedisManager;
use PDO;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Laravel\DatabaseFlowStateStore;
use Webong\WorkFlow\Laravel\RedisFlowStateStore;
use Webong\WorkFlow\Laravel\Models\WorkflowStateRecord;
use Webong\WorkFlow\Services\DefaultFlowStateSerializer;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;

final class ProductionFlowStateStoreTest extends TestCase
{
    public function test_postgres_store_uses_the_production_database_driver(): void
    {
        $connection = $this->postgresConnection();
        $connection->getSchemaBuilder()->dropIfExists('workflow_states');
        $connection->getSchemaBuilder()->create('workflow_states', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 100);
            $table->string('subject_id', 191);
            $table->string('flow_key', 150);
            $table->json('state');
            $table->unsignedInteger('schema_version')->default(1);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->boolean('initialized')->default(true);
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'flow_key']);
        });

        $store = new DatabaseFlowStateStore(
            new FlowStateSubject('channel', 'postgres'),
            new DefaultFlowStateSerializer(),
            $connection,
            new WorkflowStateRecord(),
        );

        $store->put('setup', new FlowState(FlowStatus::RUNNING));
        $store->mutate('setup', static fn (?FlowState $state): FlowState => new FlowState(
            FlowStatus::COMPLETED,
            metadata: ['previous' => $state?->status->value],
        ));

        self::assertSame('completed', $store->get('setup')?->status->value);
        self::assertSame('running', $store->get('setup')?->metadata['previous']);
    }

    public function test_redis_store_uses_the_production_lock_provider(): void
    {
        $manager = new RedisManager(new Container(), 'phpredis', [
            'default' => [
                'host' => $this->requiredEnvironment('WORK_FLOW_REDIS_HOST'),
                'port' => (int) $this->requiredEnvironment('WORK_FLOW_REDIS_PORT'),
                'database' => 15,
            ],
        ]);
        $redisStore = new RedisStore($manager, 'work-flow-integration');
        $store = new RedisFlowStateStore(
            new FlowStateSubject('channel', 'redis'),
            new DefaultFlowStateSerializer(),
            new Repository($redisStore),
            $redisStore,
        );

        $store->forget('setup');
        $store->put('setup', new FlowState(FlowStatus::RUNNING));
        $store->mutate('setup', static fn (?FlowState $state): FlowState => new FlowState(
            FlowStatus::COMPLETED,
            metadata: ['previous' => $state?->status->value],
        ));

        self::assertSame('completed', $store->get('setup')?->status->value);
        self::assertSame('running', $store->get('setup')?->metadata['previous']);

        $store->forget('setup');
    }

    private function postgresConnection(): PostgresConnection
    {
        $connection = new PostgresConnection(
            new PDO(
                $this->requiredEnvironment('WORK_FLOW_POSTGRES_DSN'),
                $this->requiredEnvironment('WORK_FLOW_POSTGRES_USER'),
                $this->requiredEnvironment('WORK_FLOW_POSTGRES_PASSWORD'),
            ),
            'workflow_test',
            '',
            ['name' => 'workflow-integration'],
        );

        Model::setConnectionResolver(new ConnectionResolver(['workflow-integration' => $connection]));

        return $connection;
    }

    private function requiredEnvironment(string $key): string
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            self::markTestSkipped("{$key} is not configured.");
        }

        return $value;
    }
}
