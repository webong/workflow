<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Contracts;

/**
 * A feature in an OWS document that is not supported by the current implementation.
 */
final readonly class UnsupportedFeature
{
    public function __construct(
        public string $feature,
        public string $path,
        public string $reason,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'feature' => $this->feature,
            'path' => $this->path,
            'reason' => $this->reason,
        ];
    }
}