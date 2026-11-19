<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Conditions;

final readonly class ConditionResult
{
    private function __construct(public ?bool $value, public ?string $error = null) {}

    public static function decided(bool $value): self { return new self($value); }
    public static function failed(string $error): self { return new self(null, $error); }
    public function matched(): bool { return $this->value === true; }
    public function isError(): bool { return $this->error !== null; }
}
