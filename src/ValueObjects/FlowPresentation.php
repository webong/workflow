<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

final readonly class FlowPresentation
{
    /**
     * @param list<FlowAction> $actions
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $severity,
        public string $message,
        public array $actions = [],
        public array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'message' => $this->message,
            'actions' => array_map(static fn (FlowAction $action): array => $action->toArray(), $this->actions),
            'metadata' => $this->metadata,
        ];
    }
}
