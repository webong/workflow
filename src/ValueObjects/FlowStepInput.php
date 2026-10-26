<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

/**
 * The typed, resolved input handed to a step executor.
 *
 * Keys are the binding names declared on the step definition. A key is
 * present only when its binding resolved; a present key may still carry a
 * JSON null. Optional bindings that did not resolve are omitted rather than
 * filled with a default, so `has()` distinguishes absent from null.
 *
 * Resolved input is transported only through this object — never through
 * context metadata or an `_input` key.
 */
final readonly class FlowStepInput
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public array $values = [],
    ) {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->values) ? $this->values[$name] : $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
