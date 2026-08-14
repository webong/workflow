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
    ) {
        if ($this->flowKey === '' || $this->stepId === '' || $this->idempotencyKey === '') {
            throw new InvalidArgumentException('Deferred completions require flow, step, and idempotency keys.');
        }
    }
}
