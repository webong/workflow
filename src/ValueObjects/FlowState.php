<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use Webong\WorkFlow\Enums\FlowStatus;

final readonly class FlowState
{
    public const int SCHEMA_VERSION = 1;
    /**
     * @param array<string, StepState> $steps
     * @param list<string> $failedSteps
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public FlowStatus $status,
        public array $steps = [],
        public ?string $currentStep = null,
        public array $failedSteps = [],
        public ?string $canRetryStep = null,
        public ?string $message = null,
        public array $metadata = [],
        public int $version = 1,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $steps = [];

        foreach (is_array($data['steps'] ?? null) ? $data['steps'] : [] as $id => $step) {
            if (is_string($id) && is_array($step)) {
                $steps[$id] = StepState::fromArray($step);
            }
        }

        $status = $data['status'] ?? null;
        $failedSteps = $data['failed_steps'] ?? [];
        $message = $data['status_message'] ?? $data['message'] ?? null;
        $version = $data['version'] ?? null;

        return new self(
            status: is_string($status) ? FlowStatus::tryFrom($status) ?? FlowStatus::PENDING : FlowStatus::PENDING,
            steps: $steps,
            currentStep: is_string($data['current_step'] ?? null) ? $data['current_step'] : null,
            failedSteps: is_array($failedSteps) ? array_values(array_filter($failedSteps, 'is_string')) : [],
            canRetryStep: is_string($data['can_retry_step'] ?? null) ? $data['can_retry_step'] : null,
            message: is_string($message) ? $message : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            version: is_int($version) ? max(1, $version) : 1,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'steps' => array_map(static fn (StepState $step): array => $step->toArray(), $this->steps),
            'current_step' => $this->currentStep,
            'failed_steps' => $this->failedSteps,
            'can_retry_step' => $this->canRetryStep,
            'status_message' => $this->message,
            'metadata' => $this->metadata,
            'version' => $this->version,
        ];
    }

    public function withStep(string $stepId, StepState $step): self
    {
        return new self(
            status: $this->status,
            steps: [...$this->steps, $stepId => $step],
            currentStep: $stepId,
            failedSteps: $this->failedSteps,
            canRetryStep: $this->canRetryStep,
            message: $this->message,
            metadata: $this->metadata,
            version: $this->version,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        return new self(
            status: $this->status,
            steps: $this->steps,
            currentStep: $this->currentStep,
            failedSteps: $this->failedSteps,
            canRetryStep: $this->canRetryStep,
            message: $this->message,
            metadata: [...$this->metadata, ...$metadata],
            version: $this->version,
        );
    }
}
