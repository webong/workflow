<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use Webong\WorkFlow\Enums\FlowStepStatus;

final readonly class StepResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public FlowStepStatus $status,
        public ?string $message = null,
        public ?string $error = null,
        public ?bool $retriable = null,
        public int $attempts = 0,
        public ?int $nextRetryAt = null,
        public array $metadata = [],
        public bool $deferred = false,
        public ?StepOutput $output = null,
        public ?int $deadlineAt = null,
    ) {
    }

    /** @param array<string, mixed> $metadata */
    public static function completed(?string $message = null, array $metadata = [], ?StepOutput $output = null): self
    {
        return new self(FlowStepStatus::COMPLETED, $message, metadata: $metadata, output: $output);
    }

    /** @param array<string, mixed> $metadata */
    public static function failed(string $error, bool $retriable = false, array $metadata = [], ?int $nextRetryAt = null): self
    {
        return new self(FlowStepStatus::FAILED, error: $error, retriable: $retriable, nextRetryAt: $nextRetryAt, metadata: $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function skipped(?string $message = null, array $metadata = [], ?StepOutput $output = null): self
    {
        return new self(FlowStepStatus::SKIPPED, $message, metadata: $metadata, output: $output);
    }

    /** @param array<string, mixed> $metadata */
    public static function pending(?string $message = null, array $metadata = []): self
    {
        return new self(FlowStepStatus::PENDING, $message, metadata: $metadata);
    }

    /**
     * Leaves the step waiting for an external outcome. `nextRetryAt` is the
     * retry hint for failed attempts; `deadlineAt` is the absolute wait
     * deadline for this attempt — the host applies the timeout transition
     * when it passes (core owns no scheduler).
     *
     * @param array<string, mixed> $metadata
     */
    public static function deferred(?string $message = null, ?int $nextRetryAt = null, array $metadata = [], ?int $deadlineAt = null): self
    {
        return new self(FlowStepStatus::PENDING, message: $message, nextRetryAt: $nextRetryAt, metadata: [...$metadata, 'deferred' => true], deferred: true, deadlineAt: $deadlineAt);
    }

    public function toState(): StepState
    {
        return new StepState(
            status: $this->status,
            message: $this->message,
            error: $this->error,
            updatedAt: date(\DATE_ATOM),
            retriable: $this->retriable,
            attempts: $this->attempts,
            nextRetryAt: $this->nextRetryAt,
            metadata: $this->metadata,
            output: $this->output,
            deadlineAt: $this->deadlineAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'error' => $this->error,
            'retriable' => $this->retriable,
            'attempts' => $this->attempts,
            'nextRetryAt' => $this->nextRetryAt,
            'metadata' => $this->metadata,
            'deferred' => $this->deferred,
            'output' => $this->output?->value,
            'deadlineAt' => $this->deadlineAt,
        ];
    }
}
