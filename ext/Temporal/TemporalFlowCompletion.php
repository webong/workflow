<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Temporal;

use InvalidArgumentException;
use Webong\WorkFlow\Enums\FlowStepStatus;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\StepResult;

/**
 * Serializes deferred callback payloads used by Temporal Signals.
 */
final readonly class TemporalFlowCompletion
{
    public function __construct(public FlowDeferredCompletion $completion)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = $this->completion->result;

        return [
            'flow_key' => $this->completion->flowKey,
            'step_id' => $this->completion->stepId,
            'idempotency_key' => $this->completion->idempotencyKey,
            'result' => [
                'status' => $result->status->value,
                'message' => $result->message,
                'error' => $result->error,
                'retriable' => $result->retriable,
                'attempts' => $result->attempts,
                'next_retry_at' => $result->nextRetryAt,
                'metadata' => $result->metadata,
                'deferred' => $result->deferred,
            ],
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $result = $data['result'] ?? null;

        if (! is_array($result)) {
            throw new InvalidArgumentException('A Temporal flow completion requires a result payload.');
        }

        $status = $result['status'] ?? null;

        return new self(new FlowDeferredCompletion(
            flowKey: is_string($data['flow_key'] ?? null) ? $data['flow_key'] : '',
            stepId: is_string($data['step_id'] ?? null) ? $data['step_id'] : '',
            idempotencyKey: is_string($data['idempotency_key'] ?? null) ? $data['idempotency_key'] : '',
            result: new StepResult(
                status: is_string($status)
                    ? FlowStepStatus::tryFrom($status) ?? FlowStepStatus::PENDING
                    : FlowStepStatus::PENDING,
                message: is_string($result['message'] ?? null) ? $result['message'] : null,
                error: is_string($result['error'] ?? null) ? $result['error'] : null,
                retriable: is_bool($result['retriable'] ?? null) ? $result['retriable'] : null,
                attempts: is_int($result['attempts'] ?? null) ? max(0, $result['attempts']) : 0,
                nextRetryAt: is_int($result['next_retry_at'] ?? null) ? $result['next_retry_at'] : null,
                metadata: is_array($result['metadata'] ?? null) ? $result['metadata'] : [],
                deferred: ($result['deferred'] ?? false) === true,
            ),
        ));
    }
}
