<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Closure;
use InvalidArgumentException;
use Webong\WorkFlow\Contracts\ForgettableFlowStateStore;
use Webong\WorkFlow\ValueObjects\FlowState;

/** Composes with any subject-bound store; legacy flow keys remain untouched. */
final readonly class RunScopedFlowStateStore implements ForgettableFlowStateStore
{
    public function __construct(
        private ForgettableFlowStateStore $store,
        public string $runId,
    ) {
        if ($runId === '') {
            throw new InvalidArgumentException('A run-scoped store requires a run id.');
        }
    }

    public function get(string $flowKey): ?FlowState
    {
        $state = $this->store->get($this->key($flowKey));
        $this->assertIdentity($flowKey, $state);

        return $state;
    }

    public function put(string $flowKey, FlowState $state): void
    {
        $this->mutate($flowKey, static fn (?FlowState $current): FlowState => $state);
    }

    public function mutate(string $flowKey, Closure $transition): FlowState
    {
        return $this->store->mutate($this->key($flowKey), function (?FlowState $current) use ($flowKey, $transition): FlowState {
            $this->assertIdentity($flowKey, $current);
            $next = $transition($current);
            $this->assertIdentity($flowKey, $next);

            if ($current?->run !== null && $next->run !== null) {
                $current->run->assertDefinition($next->run->definition);
            }
            $driver = $current?->metadata['execution_driver'] ?? null;
            if (is_string($driver) && $driver !== ($next->metadata['execution_driver'] ?? null)) {
                throw new InvalidArgumentException('A stored run cannot change its execution driver.');
            }

            if ($current !== null && in_array($current->status, [\Webong\WorkFlow\Enums\FlowStatus::COMPLETED, \Webong\WorkFlow\Enums\FlowStatus::CANCELLED], true)
                && $next->status !== $current->status) {
                throw new InvalidArgumentException('A terminal run cannot be reopened.');
            }

            return $next;
        });
    }

    public function forget(string $flowKey): void
    {
        $this->get($flowKey);
        $this->store->forget($this->key($flowKey));
    }

    private function key(string $flowKey): string
    {
        if ($flowKey === '') {
            throw new InvalidArgumentException('A flow key cannot be empty.');
        }

        return '__workflow_run__:'.hash('sha256', json_encode([$flowKey, $this->runId], JSON_THROW_ON_ERROR));
    }

    private function assertIdentity(string $flowKey, ?FlowState $state): void
    {
        if ($state !== null && ($state->run?->id !== $this->runId || $state->run->definition->key !== $flowKey)) {
            throw new InvalidArgumentException('Stored state does not belong to the requested flow run.');
        }
    }
}
