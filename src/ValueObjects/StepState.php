<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use Webong\WorkFlow\Enums\FlowStepStatus;

final readonly class StepState
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public FlowStepStatus $status = FlowStepStatus::PENDING,
        public ?string $message = null,
        public ?string $error = null,
        public ?string $updatedAt = null,
        public ?bool $retriable = null,
        public int $attempts = 0,
        public ?int $nextRetryAt = null,
        public array $metadata = [],
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = $data['status'] ?? null;
        $attempts = $data['attempts'] ?? null;

        return new self(
            status: is_string($status) ? FlowStepStatus::tryFrom($status) ?? FlowStepStatus::PENDING : FlowStepStatus::PENDING,
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
            updatedAt: is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null,
            retriable: is_bool($data['retriable'] ?? null) ? $data['retriable'] : null,
            attempts: is_int($attempts) ? max(0, $attempts) : 0,
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
