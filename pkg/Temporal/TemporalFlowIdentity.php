<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Temporal;

use InvalidArgumentException;
use Webong\WorkFlow\ValueObjects\FlowStateSubject;

/**
 * Creates a stable Temporal Workflow ID for a subject-bound flow.
 */
final readonly class TemporalFlowIdentity
{
    public function __construct(
        public FlowStateSubject $subject,
        public string $flowKey,
        public ?string $runId = null,
    ) {
        if ($this->flowKey === '' || $this->runId === '') {
            throw new InvalidArgumentException('A Temporal flow identity requires a flow key.');
        }
    }

    public function workflowId(): string
    {
        $parts = [
            'work-flow',
            rawurlencode($this->subject->type),
            rawurlencode($this->subject->id),
            rawurlencode($this->flowKey),
        ];

        if ($this->runId !== null) {
            $parts[] = rawurlencode($this->runId);
        }

        return implode(':', $parts);
    }
}
