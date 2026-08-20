<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel;

use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Webong\WorkFlow\Contracts\FlowStateSerializer;
use Webong\WorkFlow\Contracts\ForgettableFlowStateStore;
use Webong\WorkFlow\Laravel\Models\WorkflowStateRecord;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;

final class DatabaseFlowStateStore implements ForgettableFlowStateStore
{
    public function __construct(
        private readonly FlowStateSubject $subject,
        private readonly FlowStateSerializer $serializer,
        private readonly Connection $connection,
        private readonly WorkflowStateRecord $records = new WorkflowStateRecord(),
    ) {
        $this->records->setConnection($connection->getName());
    }

    public function get(string $flowKey): ?FlowState
    {
        $record = $this->record($flowKey, locked: false, columns: ['state', 'initialized']);

        if ($record === null || ! $record->initialized) {
            return null;
        }

        return $this->serializer->deserialize($record->state);
    }

    public function put(string $flowKey, FlowState $state): void
    {
        $this->mutate($flowKey, static fn (?FlowState $current): FlowState => $state);
    }

    public function mutate(string $flowKey, Closure $transition): FlowState
    {
        return $this->connection->transaction(function () use ($flowKey, $transition): FlowState {
            $record = $this->record($flowKey, locked: true);

            if ($record === null) {
                $this->connection->table($this->records->getTable())->insertOrIgnore([
                    'subject_type' => $this->subject->type,
                    'subject_id' => $this->subject->id,
                    'flow_key' => $flowKey,
                    'state' => json_encode((new FlowState(\Webong\WorkFlow\Enums\FlowStatus::PENDING))->toArray(), JSON_THROW_ON_ERROR),
                    'schema_version' => FlowState::SCHEMA_VERSION,
                    'lock_version' => 0,
                    'initialized' => false,
                    'created_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);

                $record = $this->record($flowKey, locked: true);
            }

            if ($record === null) {
                throw new RuntimeException('Unable to create or lock the workflow state record.');
            }

            $current = $record->initialized ? $this->serializer->deserialize($record->state) : null;
            $next = $transition($current);

            $record->state = $this->serializer->serialize($next);
            $record->schema_version = FlowState::SCHEMA_VERSION;
            $record->lock_version = ((int) $record->lock_version) + 1;
            $record->initialized = true;
            $record->save();

            return $next;
        });
    }

    public function forget(string $flowKey): void
    {
        $this->query($flowKey)->delete();
    }

    /** @return Builder<WorkflowStateRecord> */
    private function query(string $flowKey): Builder
    {
        return $this->records->newQuery()
            ->where('subject_type', $this->subject->type)
            ->where('subject_id', $this->subject->id)
            ->where('flow_key', $flowKey);
    }

    /** @param list<string>|null $columns */
    private function record(string $flowKey, bool $locked, ?array $columns = null): ?WorkflowStateRecord
    {
        $query = $this->query($flowKey);

        if ($locked) {
            $query->lockForUpdate();
        }

        $record = $query->first($columns ?? ['*']);

        return $record instanceof WorkflowStateRecord ? $record : null;
    }
}
