<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;
use Webong\WorkFlow\Enums\FlowStatus;
use Webong\WorkFlow\Services\CanonicalFlowPayload;

/** The identity and immutable definition snapshot of one occurrence of a flow. */
final readonly class FlowRun
{
    public FlowDefinition $definition;

    public function __construct(
        public string $id,
        FlowDefinition $definition,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('A flow run requires an id.');
        }
        $snapshot = json_decode(json_encode(
            CanonicalFlowPayload::normalize($definition->toArray()),
            JSON_THROW_ON_ERROR,
        ), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($snapshot)) {
            throw new InvalidArgumentException('Invalid flow definition snapshot.');
        }
        $this->definition = FlowDefinition::fromArray($snapshot);
    }

    public function initialState(): FlowState
    {
        return new FlowState(FlowStatus::PENDING, run: $this);
    }

    public function assertDefinition(FlowDefinition $definition): void
    {
        if (CanonicalFlowPayload::fingerprint($definition->toArray()) !== CanonicalFlowPayload::fingerprint($this->definition->toArray())) {
            throw new InvalidArgumentException('A flow run must use its pinned definition snapshot.');
        }
    }

    /** @return array{id: string, definition: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'definition' => $this->definition->toArray()];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! is_string($data['id'] ?? null) || ! is_array($data['definition'] ?? null)) {
            throw new InvalidArgumentException('Invalid flow run snapshot.');
        }

        return new self($data['id'], FlowDefinition::fromArray($data['definition']));
    }

}
