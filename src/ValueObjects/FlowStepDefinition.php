<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;

final readonly class FlowStepDefinition
{
    /**
     * @param list<string> $dependsOn
     * @param array<string, FlowInputBinding> $input Declarative input bindings, keyed by input name.
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $label,
        public bool $critical = true,
        public bool $retriable = false,
        public array $dependsOn = [],
        public array $metadata = [],
        public ?FlowRetryPolicy $retryPolicy = null,
        public array $input = [],
    ) {
        if ($this->id === '') {
            throw new \InvalidArgumentException('A flow step must have an id.');
        }
    }

    /** @param mixed $data */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw new \InvalidArgumentException('FlowStepDefinition data must be an array.');
        }
        $id = $data['id'] ?? null;
        $label = $data['label'] ?? $id;
        $retryPolicy = $data['retry_policy'] ?? null;
        $maxAttempts = is_array($retryPolicy) ? ($retryPolicy['max_attempts'] ?? null) : null;
        $backoffSeconds = is_array($retryPolicy) ? ($retryPolicy['backoff_seconds'] ?? null) : null;
        $metadataValue = $data['metadata'] ?? null;
        $metadata = (array) (is_array($metadataValue) ? $metadataValue : []);
        $input = self::bindingsFromArray($data['input'] ?? [], is_string($id) ? $id : '');

        return new self(
            id: is_string($id) ? $id : '',
            label: is_string($label) ? $label : '',
            critical: (bool) ($data['critical'] ?? true),
            retriable: (bool) ($data['retriable'] ?? false),
            dependsOn: is_array($data['depends_on'] ?? null)
                ? array_values(array_filter($data['depends_on'], 'is_string'))
                : [],
            metadata: $metadata,
            retryPolicy: is_array($retryPolicy)
                ? new FlowRetryPolicy(
                    enabled: is_bool($retryPolicy['enabled'] ?? null) ? $retryPolicy['enabled'] : true,
                    maxAttempts: is_int($maxAttempts) ? $maxAttempts : 3,
                    backoffSeconds: is_int($backoffSeconds) ? $backoffSeconds : 0,
                    idempotent: is_bool($retryPolicy['idempotent'] ?? null) ? $retryPolicy['idempotent'] : true,
                )
                : null,
            input: $input,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'label' => $this->label,
            'critical' => $this->critical,
            'retriable' => $this->retriable,
            'depends_on' => $this->dependsOn,
            'metadata' => $this->metadata,
            'retry_policy' => $this->retryPolicy?->toArray(),
        ];

        // Omitted when empty so definitions serialized before bindings
        // existed keep the exact same canonical shape.
        if ($this->input !== []) {
            $bindings = [];
            foreach ($this->input as $name => $binding) {
                $bindings[$name] = $binding instanceof FlowInputBinding
                    ? $binding->toArray()
                    : $binding;
            }
            $data['input'] = $bindings;
        }

        return $data;
    }

    /**
     * @param mixed $bindings
     * @return array<string, FlowInputBinding>
     */
    private static function bindingsFromArray(mixed $bindings, string $stepId): array
    {
        if (! is_array($bindings)) {
            throw new \InvalidArgumentException("Flow step '{$stepId}' input bindings must be an object.");
        }

        $parsed = [];
        foreach ($bindings as $name => $binding) {
            if (! is_string($name) || $name === '') {
                throw new \InvalidArgumentException("Flow step '{$stepId}' input binding names must be non-empty strings.");
            }
            $parsed[$name] = $binding instanceof FlowInputBinding
                ? $binding
                : FlowInputBinding::fromArray($binding);
        }

        return $parsed;
    }
}
