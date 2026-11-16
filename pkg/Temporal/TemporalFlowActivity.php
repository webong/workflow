<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Temporal;

/**
 * Temporal SDK activity entry point for a WorkFlow step.
 *
 * temporal/sdk is optional and must be installed by the host application.
 */
final class TemporalFlowActivity implements TemporalFlowActivityInterface
{
    private readonly TemporalFlowActivityHandler $handler;

    /** @param iterable<\Webong\WorkFlow\Contracts\FlowStepExecutor> $executors */
    public function __construct(iterable $executors)
    {
        $this->handler = new TemporalFlowActivityHandler($executors);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array
    {
        return $this->handler->execute($input);
    }
}
