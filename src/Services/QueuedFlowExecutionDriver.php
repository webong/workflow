<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Closure;
use Webong\WorkFlow\Contracts\FlowExecutionDriver;
use Webong\WorkFlow\Enums\FlowExecutionStatus;
use Webong\WorkFlow\ValueObjects\FlowExecutionReceipt;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;

/** Framework-neutral queue dispatch adapter for standalone hosts. */
final readonly class QueuedFlowExecutionDriver implements FlowExecutionDriver
{
    /** @param Closure(FlowExecutionRequest): string $dispatch */
    public function __construct(private Closure $dispatch) {}

    public function name(): string
    {
        return 'queue';
    }

    public function dispatch(FlowExecutionRequest $request): FlowExecutionReceipt
    {
        $request = $request->withRecordedDriver();
        $executionId = ($this->dispatch)($request);
        if ($executionId === '') {
            $executionId = $request->executionId ?? '';
        }
        if ($executionId === '') {
            throw new \InvalidArgumentException('The queue driver must return an execution id.');
        }

        return new FlowExecutionReceipt(
            driver: $this->name(),
            status: FlowExecutionStatus::DISPATCHED,
            executionId: $executionId,
            state: $request->state,
        );
    }
}
