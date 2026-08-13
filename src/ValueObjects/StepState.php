<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\ValueObjects;

use Zorvia\WebFlow\Enums\StepStatus;

final readonly class StepState
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public StepStatus $status = StepStatus::PENDING,
        public ?string $message = null,
        public ?string $error = null,
        public ?string $updatedAt = null,
        public ?bool $retriable = null,
        public int $attempts = 0,
        public ?int $nextRetryAt = null,
        public array $metadata = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            status: StepStatus::tryFrom((string) ($data['status'] ?? StepStatus::PENDING->value)) ?? StepStatus::PENDING,
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
            updatedAt: is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null,
            retriable: is_bool($data['retriable'] ?? null) ? $data['retriable'] : null,
            attempts: max(0, (int) ($data['attempts'] ?? 0)),
            nextRetryAt: is_int($data['next_retry_at'] ?? null) ? $data['next_retry_at'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'error' => $this->error,
            'updated_at' => $this->updatedAt,
            'retriable' => $this->retriable,
            'attempts' => $this->attempts,
            'next_retry_at' => $this->nextRetryAt,
            'metadata' => $this->metadata,
        ];
    }
}
