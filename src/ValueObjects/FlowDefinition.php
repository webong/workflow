<?php

declare(strict_types=1);

namespace Webong\WebFlow\ValueObjects;

use InvalidArgumentException;

final readonly class FlowDefinition
{
    /**
     * @param list<StepDefinition> $steps
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $key,
        public array $steps = [],
        public array $metadata = [],
        public int $version = 1,
    ) {
        if ($this->key === '') {
            throw new InvalidArgumentException('A flow must have a key.');
        }

        if ($this->version < 1) {
            throw new InvalidArgumentException('A flow version must be positive.');
        }

        $ids = array_map(static fn (StepDefinition $step): string => $step->id, $this->steps);

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Flow step ids must be unique.');
        }

        foreach ($this->steps as $step) {
            foreach ($step->dependsOn as $dependency) {
                if (! in_array($dependency, $ids, true)) {
                    throw new InvalidArgumentException("Flow step '{$step->id}' depends on unknown step '{$dependency}'.");
                }
            }
        }

        $this->assertAcyclic($this->steps);
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $key = $data['key'] ?? null;
        $version = $data['version'] ?? null;

        return new self(
            key: is_string($key) ? $key : '',
            steps: array_map(
                static fn (array|StepDefinition $step): StepDefinition => $step instanceof StepDefinition
                    ? $step
                    : StepDefinition::fromArray($step),
                array_values(array_filter($steps, static fn (mixed $step): bool => is_array($step) || $step instanceof StepDefinition)),
            ),
            version: is_int($version) ? $version : 1,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    public function step(string $id): ?StepDefinition
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'steps' => array_map(static fn (StepDefinition $step): array => $step->toArray(), $this->steps),
            'metadata' => $this->metadata,
        ];
    }

    /** @param list<StepDefinition> $steps */
    private function assertAcyclic(array $steps): void
    {
        $visiting = [];
        $visited = [];

        $visit = function (string $id) use (&$visit, &$visiting, &$visited, $steps): void {
            if (isset($visiting[$id])) {
                throw new InvalidArgumentException("Flow step dependency cycle detected at '{$id}'.");
            }

            if (isset($visited[$id])) {
                return;
            }

            $visiting[$id] = true;
            $step = null;
            foreach ($steps as $candidate) {
                if ($candidate->id === $id) {
                    $step = $candidate;
                    break;
                }
            }

            if ($step instanceof StepDefinition) {
                foreach ($step->dependsOn as $dependency) {
                    $visit($dependency);
                }
            }

            unset($visiting[$id]);
            $visited[$id] = true;
        };

        foreach ($steps as $step) {
            $visit($step->id);
        }
    }
}
