<?php

declare(strict_types=1);

namespace Webong\WebFlow\ValueObjects;

use Webong\WebFlow\Contracts\FlowContext;

final readonly class ArrayFlowContext implements FlowContext
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values = [])
    {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
