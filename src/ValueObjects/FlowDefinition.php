<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;

final readonly class FlowDefinition
{
    /**
     * @param list<FlowStepDefinition> $steps
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $key,
        public array $steps = [],
        public array $metadata = [],
        public int $version = 1,
    ) {
        if ($this->key === '') {
            throw new \InvalidArgumentException('A flow must have a key.');
        }

        if ($this->version < 1) {
            throw new \InvalidArgumentException('A flow version must be positive.');
        }

        $ids = [];
        $seen = [];
        foreach ($this->steps as $step) {
            if (! $step instanceof FlowStepDefinition) {
                throw new \InvalidArgumentException('Flow steps must be step definitions.');
            }
            if (isset($seen['step:'.$step->id])) {
                throw new \InvalidArgumentException('Flow step ids must be unique.');
            }
            $seen['step:'.$step->id] = true;
            $ids[] = $step->id;
        }

        foreach ($this->steps as $step) {
            if (! $step instanceof FlowStepDefinition) {
                throw new \InvalidArgumentException('Flow steps must be step definitions.');
            }
            foreach ($step->dependsOn as $dependency) {
                if (! in_array($dependency, $ids, true)) {
                    throw new \InvalidArgumentException("Flow step '{$step->id}' depends on unknown step '{$dependency}'.");
                }
            }
        }

        $this->assertAcyclic($this->steps);
    }

    /** @param mixed $data */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw new \InvalidArgumentException('FlowDefinition data must be an array.');
        }
        $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
        $key = $data['key'] ?? null;
        $version = $data['version'] ?? null;
        // Checked locals preserve the PHP contract while exposing array types to AOT compilers.
        $metadataValue = $data['metadata'] ?? null;
        $metadata = (array) (is_array($metadataValue) ? $metadataValue : []);

        return new self(
            key: is_string($key) ? $key : '',
            steps: array_map(
                static fn (array|FlowStepDefinition $step): FlowStepDefinition => $step instanceof FlowStepDefinition
                    ? $step
                    : FlowStepDefinition::fromArray($step),
                array_values(array_filter($steps, static fn (mixed $step): bool => is_array($step) || $step instanceof FlowStepDefinition)),
            ),
            version: is_int($version) ? $version : 1,
            metadata: $metadata,
        );
    }

    public function step(string $id): ?FlowStepDefinition
    {
        foreach ($this->steps as $step) {
            if (! $step instanceof FlowStepDefinition) {
                throw new \InvalidArgumentException('Flow steps must be step definitions.');
            }
            if ($step->id === $id) {
                return $step;
            }
        }

        return null;
    }

    /** @return list<FlowStepDefinition> Dependencies first, retaining declaration order where possible. */
    public function executionSteps(): array
    {
        $ordered = [];
        $visited = [];
        $visit = function (FlowStepDefinition $step) use (&$visit, &$ordered, &$visited): void {
            if (isset($visited[$step->id])) {
                return;
            }

            foreach ($step->dependsOn as $dependency) {
                $dependencyStep = $this->step($dependency);
                if ($dependencyStep !== null) {
                    $visit($dependencyStep);
                }
            }

            $visited[$step->id] = true;
            $ordered[] = $step;
        };

        foreach ($this->steps as $step) {
            if (! $step instanceof FlowStepDefinition) {
                throw new \InvalidArgumentException('Flow steps must be step definitions.');
            }
            $visit($step);
        }

        return $ordered;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'steps' => array_map(static fn (FlowStepDefinition $step): array => $step->toArray(), $this->steps),
            'metadata' => $this->metadata,
        ];
    }

    /** @param list<FlowStepDefinition> $steps */
    private function assertAcyclic(array $steps): void
    {
        $visiting = [];
        $visited = [];

        $checkCycle = function (string $id) use (&$checkCycle, &$visiting, &$visited, $steps): void {
            if (isset($visiting[$id])) {
                throw new \InvalidArgumentException("Flow step dependency cycle detected at '{$id}'.");
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

            if ($step instanceof FlowStepDefinition) {
                foreach ($step->dependsOn as $dependency) {
                    $checkCycle($dependency);
                }
            }

            unset($visiting[$id]);
            $visited[$id] = true;
        };

        foreach ($steps as $step) {
            if ($step instanceof FlowStepDefinition) {
                $id = (string) $step->id;
                $checkCycle($id);
            }
        }
    }
}
