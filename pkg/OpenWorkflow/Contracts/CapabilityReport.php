<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowDefinition;

/**
 * Result of validating and analyzing an OWS document.
 */
final readonly class CapabilityReport
{
    /**
     * @param list<string> $supported
     * @param list<UnsupportedFeature> $unsupported
     * @param list<string> $warnings
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $valid,
        public string $version,
        public array $supported = [],
        public array $unsupported = [],
        public array $warnings = [],
        public ?FlowDefinition $flowDefinition = null,
        public array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'version' => $this->version,
            'supported' => $this->supported,
            'unsupported' => array_map(
                static fn (UnsupportedFeature $f): array => $f->toArray(),
                $this->unsupported,
            ),
            'warnings' => $this->warnings,
            'flow_definition' => $this->flowDefinition?->toArray(),
            'metadata' => $this->metadata,
        ];
    }

    public static function invalid(string $version, array $errors): self
    {
        return new self(
            valid: false,
            version: $version,
            unsupported: array_map(
                static fn (string $e): UnsupportedFeature => new UnsupportedFeature(
                    feature: 'schema-validation',
                    path: '',
                    reason: $e,
                ),
                $errors,
            ),
        );
    }

    public static function valid(
        string $version,
        array $supported,
        array $unsupported,
        array $warnings,
        ?FlowDefinition $flowDefinition = null,
        array $metadata = [],
    ): self {
        return new self(
            valid: true,
            version: $version,
            supported: $supported,
            unsupported: $unsupported,
            warnings: $warnings,
            flowDefinition: $flowDefinition,
            metadata: $metadata,
        );
    }
}