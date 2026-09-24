<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;

final readonly class FlowRetryPolicy
{
    public function __construct(
        public bool $enabled = true,
        public int $maxAttempts = 3,
        public int $backoffSeconds = 0,
        public bool $idempotent = true,
    ) {
        if ($this->maxAttempts < 1 || $this->backoffSeconds < 0) {
            throw new InvalidArgumentException('Retry attempts must be positive and backoff cannot be negative.');
        }
    }

    public function canRetry(int $attempts): bool
    {
        return $this->enabled && $this->idempotent && $attempts < $this->maxAttempts;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'max_attempts' => $this->maxAttempts,
            'backoff_seconds' => $this->backoffSeconds,
            'idempotent' => $this->idempotent,
        ];
    }
}
