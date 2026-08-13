<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\ValueObjects;

use InvalidArgumentException;

final readonly class StepDefinition
{
    /**
     * @param list<string> $dependsOn
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $label,
        public bool $critical = true,
        public bool $retriable = false,
        public array $dependsOn = [],
        public array $metadata = [],
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException('A flow step must have an id.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            label: (string) ($data['label'] ?? $data['id'] ?? ''),
            critical: (bool) ($data['critical'] ?? true),
            retriable: (bool) ($data['retriable'] ?? false),
            dependsOn: is_array($data['depends_on'] ?? null)
                ? array_values(array_filter($data['depends_on'], 'is_string'))
                : [],
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'critical' => $this->critical,
            'retriable' => $this->retriable,
            'depends_on' => $this->dependsOn,
            'metadata' => $this->metadata,
        ];
    }
}
