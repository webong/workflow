<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use Webong\WorkFlow\Enums\FlowExecutionStatus;

final readonly class FlowChildRunReceipt
{
    public function __construct(
        public string $childFlowKey,
        public string $childRunId,
        public FlowExecutionStatus $status,
        public ?StepOutput $output = null,
    ) {
        if ($this->childFlowKey === '' || $this->childRunId === '') {
            throw new \InvalidArgumentException('A child run receipt requires flow and run identities.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'child_flow_key' => $this->childFlowKey,
            'child_run_id' => $this->childRunId,
            'status' => $this->status->value,
        ];
        if ($this->output !== null) {
            $data['output'] = $this->output->value;
        }

        return $data;
    }

    /** @param mixed $data */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw new \InvalidArgumentException('FlowChildRunReceipt data must be an array.');
        }
        $status = is_string($data['status'] ?? null)
            ? FlowExecutionStatus::tryFrom($data['status'])
            : null;
        if ($status === null) {
            throw new \InvalidArgumentException('A child run receipt requires a valid status.');
        }

        return new self(
            childFlowKey: is_string($data['child_flow_key'] ?? null) ? $data['child_flow_key'] : '',
            childRunId: is_string($data['child_run_id'] ?? null) ? $data['child_run_id'] : '',
            status: $status,
            output: array_key_exists('output', $data) ? StepOutput::from($data['output']) : null,
        );
    }
}
