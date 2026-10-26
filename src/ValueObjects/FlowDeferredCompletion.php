<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;

final readonly class FlowDeferredCompletion
{
    public function __construct(
        public string $flowKey,
        public string $stepId,
        public string $idempotencyKey,
        public StepResult $result,
        public ?string $runId = null,
        public ?int $attempt = null,
    ) {
        if ($this->flowKey === '' || $this->stepId === '' || $this->idempotencyKey === '') {
            throw new \InvalidArgumentException('Deferred completions require flow, step, and idempotency keys.');
        }

        if ($runId === '' || ($attempt !== null && $attempt < 1)) {
            throw new \InvalidArgumentException('Completion run ids must be non-empty and attempts must be positive.');
        }

        if ($result->status === \Webong\WorkFlow\Enums\FlowStepStatus::RUNNING) {
            throw new \InvalidArgumentException('A deferred completion cannot start execution.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'flow_key' => $this->flowKey,
            'step_id' => $this->stepId,
            'idempotency_key' => $this->idempotencyKey,
            'result' => $this->result->toArray(),
            'run_id' => $this->runId,
            'attempt' => $this->attempt,
        ];
    }

    /** @param mixed $data */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw new \InvalidArgumentException('FlowDeferredCompletion data must be an array.');
        }

        $resultData = $data['result'] ?? null;
        if (! is_array($resultData)) {
            throw new \InvalidArgumentException('FlowDeferredCompletion result must be an array.');
        }

        return new self(
            flowKey: is_string($data['flow_key'] ?? null) ? $data['flow_key'] : '',
            stepId: is_string($data['step_id'] ?? null) ? $data['step_id'] : '',
            idempotencyKey: is_string($data['idempotency_key'] ?? null) ? $data['idempotency_key'] : '',
            result: \Webong\WorkFlow\ValueObjects\StepResult::fromArray($resultData),
            runId: is_string($data['run_id'] ?? null) ? $data['run_id'] : null,
            attempt: is_int($data['attempt'] ?? null) ? $data['attempt'] : null,
        );
    }
}
