<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;
use Webong\WorkFlow\Enums\FlowExecutionStatus;

final readonly class FlowExecutionReceipt
{
    public function __construct(
        public string $driver,
        public FlowExecutionStatus $status,
        public ?string $executionId = null,
        public ?FlowState $state = null,
    ) {
        if ($this->driver === '') {
            throw new InvalidArgumentException('A flow execution receipt requires a driver.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'status' => $this->status->value,
            'execution_id' => $this->executionId,
            'state' => $this->state?->toArray(),
        ];
    }
}
