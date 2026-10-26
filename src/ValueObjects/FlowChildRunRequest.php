<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

/**
 * Runtime-neutral child-run identity and input.
 *
 * The parent attempt makes dispatch idempotency explicit: a retry is a new
 * child identity, while replaying the same attempt addresses the same child.
 */
final readonly class FlowChildRunRequest
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $childFlowKey,
        public string $childRunId,
        public array $input,
        public string $parentFlowKey,
        public ?string $parentRunId,
        public string $parentStepId,
        public int $parentAttempt,
        public bool $await = true,
    ) {
        foreach ([$this->childFlowKey, $this->childRunId, $this->parentFlowKey, $this->parentStepId] as $identity) {
            if ($identity === '') {
                throw new \InvalidArgumentException('Child run identities must be non-empty.');
            }
        }
        if ($this->parentAttempt < 1) {
            throw new \InvalidArgumentException('A child run requires a positive parent attempt.');
        }
        foreach ($this->input as $key => $_) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Child run input must be an object.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'child_flow_key' => $this->childFlowKey,
            'child_run_id' => $this->childRunId,
            'input' => $this->input,
            'parent_flow_key' => $this->parentFlowKey,
            'parent_run_id' => $this->parentRunId,
            'parent_step_id' => $this->parentStepId,
            'parent_attempt' => $this->parentAttempt,
            'await' => $this->await,
        ];
    }

    /** @param mixed $data */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw new \InvalidArgumentException('FlowChildRunRequest data must be an array.');
        }
        $input = $data['input'] ?? null;

        return new self(
            childFlowKey: is_string($data['child_flow_key'] ?? null) ? $data['child_flow_key'] : '',
            childRunId: is_string($data['child_run_id'] ?? null) ? $data['child_run_id'] : '',
            input: is_array($input) ? $input : [],
            parentFlowKey: is_string($data['parent_flow_key'] ?? null) ? $data['parent_flow_key'] : '',
            parentRunId: is_string($data['parent_run_id'] ?? null) ? $data['parent_run_id'] : null,
            parentStepId: is_string($data['parent_step_id'] ?? null) ? $data['parent_step_id'] : '',
            parentAttempt: is_int($data['parent_attempt'] ?? null) ? $data['parent_attempt'] : 0,
            await: is_bool($data['await'] ?? null) ? $data['await'] : true,
        );
    }
}
