<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Webong\WorkFlow\Contracts\FlowOperationProvider;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowOperation;

/**
 * Collects the optional operation descriptors host executors publish.
 *
 * Executors are matched exactly like the runner matches them — via
 * `supports()` — so a descriptor always describes the executor that would
 * run the step. The result is authoring and documentation data for hosts,
 * CLI tooling, RPC consumers, or a future editor; it is never executed,
 * and a descriptor grants no permissions by itself.
 */
final readonly class FlowOperationCatalog
{
    public function __construct(
        private FlowStepExecutorResolver $executorResolver = new FlowStepExecutorResolver(),
    ) {
    }

    /**
     * @param iterable<mixed> $executors
     * @return array<string, FlowOperation> Keyed by step id; steps without a descriptor are omitted.
     */
    public function describe(FlowDefinition $definition, iterable $executors): array
    {
        $executors = $this->executorResolver->normalize($executors);
        $operations = [];

        foreach ($definition->steps as $step) {
            $executor = $this->executorResolver->resolve($step, $executors);
            if ($executor instanceof FlowOperationProvider) {
                $operation = $executor->operation($step);
                if ($operation !== null) {
                    $operations[$step->id] = $operation;
                }
            }
        }

        return $operations;
    }
}
