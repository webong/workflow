<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;

final readonly class FlowExecutionRequest
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public FlowDefinition $definition,
        public FlowState $state,
        public array $context = [],
        public string $driver = 'inline',
        public ?string $executionId = null,
        public ?FlowStateSubject $subject = null,
    ) {
        $state->run?->assertDefinition($definition);
        if ($this->driver === '') {
            throw new InvalidArgumentException('A flow execution request requires a driver.');
        }

        if ($this->executionId === '') {
            throw new InvalidArgumentException('A flow execution id cannot be empty.');
        }
    }

    /**
     * Records the selected driver in the snapshot and prevents a running
     * execution from silently switching runtimes on a later dispatch.
     */
    public function withRecordedDriver(): self
    {
        $recordedDriver = $this->state->metadata['execution_driver'] ?? null;

        if (is_string($recordedDriver) && $recordedDriver !== $this->driver) {
            throw new InvalidArgumentException(
                "Flow execution is already assigned to the '{$recordedDriver}' driver.",
            );
        }

        if ($recordedDriver === $this->driver) {
            return $this;
        }

        return new self(
            definition: $this->definition,
            state: $this->state->withMetadata(['execution_driver' => $this->driver]),
            context: $this->context,
            driver: $this->driver,
            executionId: $this->executionId,
            subject: $this->subject,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'definition' => $this->definition->toArray(),
            'state' => $this->state->toArray(),
            'context' => $this->context,
            'driver' => $this->driver,
            'execution_id' => $this->executionId,
            'subject' => $this->subject === null
                ? null
                : ['type' => $this->subject->type, 'id' => $this->subject->id],
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $definition = $data['definition'] ?? [];
        $state = $data['state'] ?? [];
        $subject = $data['subject'] ?? null;

        return new self(
            definition: FlowDefinition::fromArray(is_array($definition) ? $definition : []),
            state: FlowState::fromArray(is_array($state) ? $state : []),
            context: is_array($data['context'] ?? null) ? self::stringKeyed($data['context']) : [],
            driver: is_string($data['driver'] ?? null) ? $data['driver'] : 'inline',
            executionId: is_string($data['execution_id'] ?? null) ? $data['execution_id'] : null,
            subject: is_array($subject)
                && is_string($subject['type'] ?? null)
                && is_string($subject['id'] ?? null)
                ? new FlowStateSubject($subject['type'], $subject['id'])
                : null,
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
