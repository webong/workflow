<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Closure;
use Webong\WorkFlow\Contracts\ForgettableFlowStateStore;
use Webong\WorkFlow\ValueObjects\FlowState;

/** Reference implementation for package consumers and conformance tests. */
final class InMemoryFlowStateStore implements ForgettableFlowStateStore
{
    /** @var array<string, FlowState> */
    private array $states = [];

    public function get(string $flowKey): ?FlowState
    {
        return $this->states[$flowKey] ?? null;
    }

    public function put(string $flowKey, FlowState $state): void
    {
        $this->states[$flowKey] = $state;
    }

    public function mutate(string $flowKey, Closure $transition): FlowState
    {
        $next = $transition($this->states[$flowKey] ?? null);

        $this->put($flowKey, $next);

        return $next;
    }

    public function forget(string $flowKey): void
    {
        unset($this->states[$flowKey]);
    }
}
