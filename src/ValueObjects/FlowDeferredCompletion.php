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
            throw new InvalidArgumentException('Deferred completions require flow, step, and idempotency keys.');
        }

        if ($runId === '' || ($attempt !== null && $attempt < 1)) {
            throw new InvalidArgumentException('Completion run ids must be non-empty and attempts must be positive.');
        }

        if ($result->status === \Webong\WorkFlow\Enums\FlowStepStatus::RUNNING) {
            throw new InvalidArgumentException('A deferred completion cannot start execution.');
        }
    }
}
