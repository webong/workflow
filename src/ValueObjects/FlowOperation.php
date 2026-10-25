<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;

/**
 * A stable description of one host-approved operation.
 *
 * The descriptor is authoring/documentation data: what the operation is
 * called, what input it accepts, what output it produces, and which
 * capabilities it declares. It never executes anything — `FlowStepExecutor`
 * remains the execution authority — and it never turns arbitrary client
 * input into runnable behavior.
 *
 * Schemas are JSON-Schema-shaped documents (or any host-chosen schema
 * form); the core treats them as opaque serializable values and never
 * interprets them.
 */
final readonly class FlowOperation
{
    /**
     * @param array<string, mixed>|null $inputSchema
     * @param array<string, mixed>|null $outputSchema
     * @param list<string> $capabilities
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $summary = null,
        public ?array $inputSchema = null,
        public ?array $outputSchema = null,
        public array $capabilities = [],
        public array $metadata = [],
    ) {
        if ($this->key === '') {
            throw new InvalidArgumentException('A flow operation requires a stable key.');
        }
    }

    /** @param mixed $data */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw new InvalidArgumentException('A flow operation descriptor must be an object.');
        }

        $key = $data['key'] ?? null;
        $label = $data['label'] ?? null;
        $summary = $data['summary'] ?? null;
        $inputSchema = $data['input_schema'] ?? null;
        $outputSchema = $data['output_schema'] ?? null;
        $capabilities = $data['capabilities'] ?? [];
        $metadataValue = $data['metadata'] ?? null;

        return new self(
            key: is_string($key) ? $key : '',
            label: is_string($label) ? $label : '',
            summary: is_string($summary) ? $summary : null,
            inputSchema: self::stringKeyed($inputSchema, 'input_schema'),
            outputSchema: self::stringKeyed($outputSchema, 'output_schema'),
            capabilities: is_array($capabilities) ? array_values(array_filter($capabilities, 'is_string')) : [],
            metadata: (array) (is_array($metadataValue) ? $metadataValue : []),
        );
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private static function stringKeyed(mixed $value, string $field): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $schemaKey => $_) {
            if (! is_string($schemaKey)) {
                throw new InvalidArgumentException("A flow operation {$field} must have string keys.");
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'summary' => $this->summary,
            'input_schema' => $this->inputSchema,
            'output_schema' => $this->outputSchema,
            'capabilities' => $this->capabilities,
            'metadata' => $this->metadata,
        ];
    }
}
