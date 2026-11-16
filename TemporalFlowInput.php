<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Temporal;

use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;

/**
 * Serializable input passed to a Temporal Workflow.
 */
final readonly class TemporalFlowInput
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public FlowDefinition $definition,
        public FlowState $state,
        public array $context = [],
    ) {
        $state->run?->assertDefinition($definition);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'definition' => $this->definition->toArray(),
            'state' => $this->state->toArray(),
            'context' => $this->context,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $definition = $data['definition'] ?? [];
        $state = $data['state'] ?? [];
        $context = $data['context'] ?? [];

        return new self(
            definition: FlowDefinition::fromArray(is_array($definition) ? $definition : []),
            state: FlowState::fromArray(is_array($state) ? $state : []),
            context: is_array($context) ? self::stringKeyed($context) : [],
        );
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
