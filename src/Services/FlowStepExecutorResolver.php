<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;

/** The single first-supporting-executor rule shared by runtime and authoring. */
final readonly class FlowStepExecutorResolver
{
    /** @param iterable<mixed> $executors */
    public function resolve(FlowStepDefinition $step, iterable $executors): ?FlowStepExecutor
    {
        foreach ($executors as $executor) {
            if ($executor instanceof FlowStepExecutor && $executor->supports($step)) {
                return $executor;
            }
        }

        return null;
    }

    /**
     * Materialize one-shot iterables at the host boundary so they can be reused.
     *
     * @param iterable<mixed> $executors
     * @return list<mixed>
     */
    public function normalize(iterable $executors): array
    {
        return is_array($executors)
            ? array_values($executors)
            : iterator_to_array($executors, false);
    }
}
