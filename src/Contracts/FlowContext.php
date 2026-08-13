<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

interface FlowContext
{
    public function has(string $key): bool;

    public function get(string $key, mixed $default = null): mixed;

    /** @return array<string, mixed> */
    public function all(): array;
}
