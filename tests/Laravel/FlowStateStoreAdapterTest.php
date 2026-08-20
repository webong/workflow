<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\Laravel;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Webong\WorkFlow\Contracts\FlowStateSerializer;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Laravel\DatabaseFlowStateStore;
use Webong\WorkFlow\Laravel\RedisFlowStateStore;
use Webong\WorkFlow\Laravel\Models\WorkflowStateRecord;
use Webong\WorkFlow\Services\DefaultFlowStateSerializer;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;

final class FlowStateStoreAdapterTest extends TestCase
{
    private FlowStateSerializer $serializer;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'testing');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->connection = $capsule->getConnection('testing');

        $this->connection->getSchemaBuilder()->create('workflow_states', function (Blueprint $table): void {
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

        $this->serializer = new DefaultFlowStateSerializer();
    }

    public function test_database_store_round_trips_state_and_isolates_subjects(): void
    {
        $first = $this->databaseStore(new FlowStateSubject('channel', 'one'));
        $second = $this->databaseStore(new FlowStateSubject('channel', 'two'));

        $first->put('setup', new FlowState(FlowStatus::RUNNING, metadata: ['owner' => 'one']));
        $second->put('setup', new FlowState(FlowStatus::COMPLETED, metadata: ['owner' => 'two']));

        self::assertSame('one', $first->get('setup')?->metadata['owner']);
        self::assertSame('two', $second->get('setup')?->metadata['owner']);
    }

    public function test_database_store_mutates_missing_and_existing_rows_atomically(): void
    {
        $store = $this->databaseStore(new FlowStateSubject('channel', 'one'));

        $created = $store->mutate('setup', static fn (?FlowState $state): FlowState => new FlowState(
            FlowStatus::PENDING,
            metadata: ['missing' => $state === null],
        ));
        $updated = $store->mutate('setup', static fn (?FlowState $state): FlowState => new FlowState(
            FlowStatus::COMPLETED,
            metadata: ['previous' => $state?->status->value],
        ));

        self::assertTrue($created->metadata['missing']);
        self::assertSame('pending', $updated->metadata['previous']);
        self::assertSame('completed', $store->get('setup')?->status->value);

        $store->forget('setup');
        self::assertNull($store->get('setup'));
    }

    public function test_redis_store_round_trips_state_mutates_and_forgets(): void
    {
        $arrayStore = new ArrayStore();
        $store = new RedisFlowStateStore(
            subject: new FlowStateSubject('channel', 'one'),
            serializer: $this->serializer,
            cache: new Repository($arrayStore),
            locks: $arrayStore,
        );

        $store->put('setup', new FlowState(FlowStatus::RUNNING));
        $state = $store->mutate('setup', static fn (?FlowState $current): FlowState => new FlowState(
            FlowStatus::COMPLETED,
            metadata: ['previous' => $current?->status->value],
        ));

        self::assertSame('running', $state->metadata['previous']);
        self::assertSame('completed', $store->get('setup')?->status->value);

        $store->forget('setup');
        self::assertNull($store->get('setup'));
    }

    private function databaseStore(FlowStateSubject $subject): DatabaseFlowStateStore
    {
        $record = new WorkflowStateRecord();
        $record->setConnection('testing');

        return new DatabaseFlowStateStore($subject, $this->serializer, $this->connection, $record);
    }
}
