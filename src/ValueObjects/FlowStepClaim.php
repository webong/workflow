<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

/**
 * A claimed step ready for execution.
 *
 * The claim includes all context needed to execute the step, plus
 * metadata for idempotent execution and lease management.
 */
final readonly class FlowStepClaim
{
    /**
     * @param array<string, mixed> $input Resolved input bindings.
     * @param array<string, mixed> $context Run context.
     * @param array<string, mixed> $previousState Previous step state.
     * @param array<string, mixed> $metadata Claim metadata (attempt, lease expiry, etc.)
     */
    public function __construct(
        public string $claimId,
        public FlowRun $run,
        public FlowStepDefinition $step,
        public FlowState $state,
        public array $input,
        public array $context,
        public array $previousState,
        public array $metadata = [],
    ) {
        if ($this->claimId === '') {
            throw new \InvalidArgumentException('Claim ID must be non-empty.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'claim_id' => $this->claimId,
            'run' => $this->run->toArray(),
            'step' => $this->step->toArray(),
            'state' => $this->state->toArray(),
            'input' => $this->input,
            'context' => $this->context,
            'previous_state' => $this->previousState,
            'metadata' => $this->metadata,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            claimId: is_string($data['claim_id'] ?? null) ? $data['claim_id'] : '',
            run: \Webong\WorkFlow\ValueObjects\FlowRun::fromArray($data['run'] ?? []),
            step: \Webong\WorkFlow\ValueObjects\FlowStepDefinition::fromArray($data['step'] ?? []),
            state: \Webong\WorkFlow\ValueObjects\FlowState::fromArray($data['state'] ?? []),
            input: is_array($data['input'] ?? null) ? $data['input'] : [],
            context: is_array($data['context'] ?? null) ? $data['context'] : [],
            previousState: is_array($data['previous_state'] ?? null) ? $data['previous_state'] : [],
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}