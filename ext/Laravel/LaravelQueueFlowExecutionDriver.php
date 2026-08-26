<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel;

use Closure;
use InvalidArgumentException;
use Webong\WorkFlow\Contracts\FlowExecutionDriver;
use Webong\WorkFlow\Enums\FlowExecutionStatus;
use Webong\WorkFlow\ValueObjects\FlowExecutionReceipt;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;

/**
 * Queue adapter that lets a Laravel host choose its own queue job and worker
 * executor resolution without adding Illuminate Queue to the core package.
 */
final readonly class LaravelQueueFlowExecutionDriver implements FlowExecutionDriver
{
    /**
     * @param Closure(FlowExecutionRequest): string $dispatch
     */
    public function __construct(private Closure $dispatch)
    {
    }

    public function name(): string
    {
        return 'queue';
    }

    public function dispatch(FlowExecutionRequest $request): FlowExecutionReceipt
    {
        $executionId = ($this->dispatch)($request);

        if ($executionId === '') {
            $executionId = $request->executionId ?? '';
        }

        if ($executionId === null || $executionId === '') {
            throw new InvalidArgumentException('The Laravel queue driver must return an execution id.');
        }

        return new FlowExecutionReceipt(
            driver: $this->name(),
            status: FlowExecutionStatus::DISPATCHED,
            executionId: $executionId,
        );
    }
}
