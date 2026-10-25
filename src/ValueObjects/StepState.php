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
        public ?StepOutput $output = null,
        public ?int $deadlineAt = null,
    ) {
    }

    /** @param mixed $data */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw new \InvalidArgumentException('StepState data must be an array.');
        }
        $status = $data['status'] ?? null;
        $attempts = $data['attempts'] ?? null;
        $metadataValue = $data['metadata'] ?? null;
        $metadata = (array) (is_array($metadataValue) ? $metadataValue : []);

        // Presence is the distinguishing bit: a stored `output: null` is a
        // present JSON null, a missing key means the step has no output.
        // Documents written before outputs existed have no key and stay
        // output-less; malformed values are ignored rather than guessed.
        $output = null;
        if (array_key_exists('output', $data)) {
            $outputValue = $data['output'];
            if (is_array($outputValue) || is_scalar($outputValue) || $outputValue === null) {
                $output = new StepOutput($outputValue);
            }
        }

        return new self(
            status: is_string($status) ? FlowStepStatus::tryFrom($status) ?? FlowStepStatus::PENDING : FlowStepStatus::PENDING,
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            error: is_string($data['error'] ?? null) ? $data['error'] : null,
            updatedAt: is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null,
            retriable: is_bool($data['retriable'] ?? null) ? $data['retriable'] : null,
            attempts: is_int($attempts) ? max(0, $attempts) : 0,
            nextRetryAt: is_int($data['next_retry_at'] ?? null) ? $data['next_retry_at'] : null,
            metadata: $metadata,
            output: $output,
            deadlineAt: is_int($data['deadline_at'] ?? null) ? $data['deadline_at'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'status' => $this->status->value,
            'message' => $this->message,
            'error' => $this->error,
            'updated_at' => $this->updatedAt,
            'retriable' => $this->retriable,
            'attempts' => $this->attempts,
            'next_retry_at' => $this->nextRetryAt,
            'metadata' => $this->metadata,
        ];

        // An absent output is never serialized as null: the key is omitted
        // so old and new documents keep the same canonical shape.
        if ($this->output !== null) {
            $data['output'] = $this->output->value;
        }

        if ($this->deadlineAt !== null) {
            $data['deadline_at'] = $this->deadlineAt;
        }

        return $data;
    }
}
