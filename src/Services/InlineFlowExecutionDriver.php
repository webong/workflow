<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowExecutionStatus;
use Webong\WorkFlow\ValueObjects\ArrayFlowContext;
use Webong\WorkFlow\ValueObjects\FlowExecutionReceipt;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;

final class InlineFlowExecutionDriver implements \Webong\WorkFlow\Contracts\FlowExecutionDriver
{
    /** @var list<FlowStepExecutor> */
    private readonly array $executors;

    /** @param iterable<FlowStepExecutor> $executors */
    public function __construct(
        private readonly FlowRunner $runner,
        iterable $executors,
    ) {
        $registered = [];

        foreach ($executors as $executor) {
            if (! $executor instanceof FlowStepExecutor) {
                throw new InvalidArgumentException('Inline flow execution requires FlowStepExecutor instances.');
            }

            $registered[] = $executor;
        }

        $this->executors = $registered;
    }

    public function name(): string
    {
        return 'inline';
    }

    public function dispatch(FlowExecutionRequest $request): FlowExecutionReceipt
    {
        $state = $this->runner->run(
            definition: $request->definition,
            state: $request->state,
            context: new ArrayFlowContext($request->context),
            executors: $this->executors,
        );

        return new FlowExecutionReceipt(
            driver: $this->name(),
            status: FlowExecutionStatus::COMPLETED,
            executionId: $request->executionId,
            state: $state,
        );
    }
}
