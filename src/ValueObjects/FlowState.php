<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\ValueObjects;

use Zorvia\WebFlow\Enums\FlowStatus;

final readonly class FlowState
{
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

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $steps = [];

        foreach (is_array($data['steps'] ?? null) ? $data['steps'] : [] as $id => $step) {
            if (is_string($id) && is_array($step)) {
                $steps[$id] = StepState::fromArray($step);
            }
        }

        return new self(
            status: FlowStatus::tryFrom((string) ($data['status'] ?? FlowStatus::PENDING->value)) ?? FlowStatus::PENDING,
            steps: $steps,
            currentStep: is_string($data['current_step'] ?? null) ? $data['current_step'] : null,
            failedSteps: array_values(array_filter($data['failed_steps'] ?? [], 'is_string')),
            canRetryStep: is_string($data['can_retry_step'] ?? null) ? $data['can_retry_step'] : null,
            message: is_string($data['status_message'] ?? $data['message'] ?? null) ? ($data['status_message'] ?? $data['message']) : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            version: max(1, (int) ($data['version'] ?? 1)),
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
}
