<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Temporal;

use InvalidArgumentException;
use Webong\WorkFlow\Services\FlowStateTransition;
use Webong\WorkFlow\Services\CanonicalFlowPayload;
use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;

/** Bounded FIFO, so progress signals cannot overwrite terminal completions. */
final class TemporalCompletionInbox
{
    /** @var array<string, list<FlowDeferredCompletion>> */
    private array $pending = [];
    private int $count = 0;
    /** @var array<string, string> */
    private array $queuedEvents = [];

    public function push(FlowDeferredCompletion $completion): void
    {
        $key = $this->key($completion);
        $fingerprint = CanonicalFlowPayload::fingerprint((new TemporalFlowCompletion($completion))->toArray());
        if (isset($this->queuedEvents[$key])) {
            if ($this->queuedEvents[$key] !== $fingerprint) {
                throw new InvalidArgumentException('A queued event id cannot be reused with a different result.');
            }
            return;
        }
        if ($this->count >= FlowStateTransition::MAX_DEFERRED_EVENTS) {
            throw new InvalidArgumentException('Temporal completion inbox is full.');
        }

        $this->pending[$completion->stepId][] = $completion;
        $this->queuedEvents[$key] = $fingerprint;
        $this->count++;
    }

    public function has(string $stepId): bool
    {
        return ($this->pending[$stepId] ?? []) !== [];
    }

    public function shift(string $stepId): ?FlowDeferredCompletion
    {
        if (! $this->has($stepId)) {
            return null;
        }

        $this->count--;

        $completion = array_shift($this->pending[$stepId]);
        if ($completion !== null) {
            unset($this->queuedEvents[$this->key($completion)]);
        }

        return $completion;
    }

    private function key(FlowDeferredCompletion $completion): string
    {
        return CanonicalFlowPayload::fingerprint([
            $completion->runId, $completion->flowKey, $completion->stepId, $completion->attempt, $completion->idempotencyKey,
        ]);
    }
}
