<?php

declare(strict_types=1);

namespace Webong\WebFlow\ValueObjects;

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
        public ?FlowRetryPolicy $retryPolicy = null,
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException('A flow step must have an id.');
        }
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $label = $data['label'] ?? $id;
        $retryPolicy = $data['retry_policy'] ?? null;
        $maxAttempts = is_array($retryPolicy) ? ($retryPolicy['max_attempts'] ?? null) : null;
        $backoffSeconds = is_array($retryPolicy) ? ($retryPolicy['backoff_seconds'] ?? null) : null;

        return new self(
            id: is_string($id) ? $id : '',
            label: is_string($label) ? $label : '',
            critical: (bool) ($data['critical'] ?? true),
            retriable: (bool) ($data['retriable'] ?? false),
            dependsOn: is_array($data['depends_on'] ?? null)
                ? array_values(array_filter($data['depends_on'], 'is_string'))
                : [],
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            retryPolicy: is_array($retryPolicy)
                ? new FlowRetryPolicy(
                    enabled: is_bool($retryPolicy['enabled'] ?? null) ? $retryPolicy['enabled'] : true,
                    maxAttempts: is_int($maxAttempts) ? $maxAttempts : 3,
                    backoffSeconds: is_int($backoffSeconds) ? $backoffSeconds : 0,
                    idempotent: is_bool($retryPolicy['idempotent'] ?? null) ? $retryPolicy['idempotent'] : true,
                )
                : null,
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
            'retry_policy' => $this->retryPolicy?->toArray(),
        ];
    }
}
