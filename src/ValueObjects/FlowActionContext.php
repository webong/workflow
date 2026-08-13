<?php

declare(strict_types=1);

namespace Webong\WebFlow\ValueObjects;

final readonly class FlowActionContext
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public mixed $actor = null,
        public mixed $resource = null,
        public array $attributes = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor' => $this->actor,
            'resource' => $this->resource,
            'attributes' => $this->attributes,
        ];
    }
}
