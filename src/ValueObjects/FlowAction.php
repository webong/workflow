<?php

declare(strict_types=1);

namespace Webong\WebFlow\ValueObjects;

final readonly class FlowAction
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $payload = [],
        public bool $enabled = true,
        public array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'payload' => $this->payload,
            'enabled' => $this->enabled,
            'metadata' => $this->metadata,
        ];
    }
}
