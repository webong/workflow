<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use Webong\WorkFlow\Contracts\FlowContext;

/**
 * Everything one step execution needs, in one typed value.
 *
 * Executors receive this single object instead of a growing positional
 * signature: the step definition, its resolved input bindings, the run
 * context, the previous step state, the flow/run identity, and the attempt
 * number (1-based; it equals `previous.attempts + 1`).
 *
 * The identity fields mirror the `_workflow` context metadata that already
 * exists for callback correlation; both stay available during the
 * transition so existing hosts keep working.
 */
final readonly class FlowStepInvocation
{
    public function __construct(
        public FlowStepDefinition $step,
        public FlowStepInput $input,
        public FlowContext $context,
        public StepState $previous,
        public string $flowKey,
        public ?string $runId,
        public int $attempt,
    ) {
    }
}
