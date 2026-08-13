<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use Zorvia\WebFlow\Contracts\FlowStateStore;
use Zorvia\WebFlow\ValueObjects\FlowState;

/** Reference implementation for package consumers and conformance tests. */
final class InMemoryFlowStateStore implements FlowStateStore
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
}
