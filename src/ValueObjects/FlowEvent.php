<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use Webong\WorkFlow\Enums\FlowEventType;

final readonly class FlowEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public FlowEventType $type,
        public string $flowKey,
        public ?string $stepId = null,
        public array $payload = [],
        public ?string $occurredAt = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'flow_key' => $this->flowKey,
            'step_id' => $this->stepId,
            'payload' => $this->payload,
            'occurred_at' => $this->occurredAt ?? date(DATE_ATOM),
        ];
    }
}
