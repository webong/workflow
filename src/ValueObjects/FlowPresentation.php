<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\ValueObjects;

use Zorvia\WebFlow\Enums\PresentationKind;

final readonly class FlowPresentation
{
    /**
     * @param list<FlowAction> $actions
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public PresentationKind $kind,
        public string $severity,
        public string $title,
        public string $message,
        public array $actions = [],
        public bool $dismissible = false,
        public array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'actions' => array_map(static fn (FlowAction $action): array => $action->toArray(), $this->actions),
            'dismissible' => $this->dismissible,
            'metadata' => $this->metadata,
        ];
    }
}
