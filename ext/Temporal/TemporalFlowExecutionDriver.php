<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Temporal;

use Closure;
use InvalidArgumentException;
use Webong\WorkFlow\Contracts\FlowExecutionDriver;
use Webong\WorkFlow\Enums\FlowExecutionStatus;
use Webong\WorkFlow\ValueObjects\FlowExecutionReceipt;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;

/**
 * SDK-neutral Temporal starter adapter.
 *
 * The host supplies the SDK-specific workflow starter so temporal/sdk remains
 * optional and the package does not own client lifecycle configuration.
 */
final readonly class TemporalFlowExecutionDriver implements FlowExecutionDriver
{
    /**
     * @param Closure(string, array<string, mixed>): string $start
     */
    public function __construct(private Closure $start)
    {
    }

    public function name(): string
    {
        return 'temporal';
    }

    public function dispatch(FlowExecutionRequest $request): FlowExecutionReceipt
    {
        $workflowId = $request->executionId;

        if ($workflowId === null && $request->subject !== null) {
            $workflowId = (new TemporalFlowIdentity(
                subject: $request->subject,
                flowKey: $request->definition->key,
            ))->workflowId();
        }

        if ($workflowId === null || $workflowId === '') {
            throw new InvalidArgumentException(
                'The Temporal driver requires an execution id or subject-bound flow request.',
            );
        }

        $startedId = ($this->start)(
            $workflowId,
            (new TemporalFlowInput(
                definition: $request->definition,
                state: $request->state,
                context: $request->context,
            ))->toArray(),
        );

        return new FlowExecutionReceipt(
            driver: $this->name(),
            status: FlowExecutionStatus::DISPATCHED,
            executionId: $startedId !== '' ? $startedId : $workflowId,
        );
    }
}
