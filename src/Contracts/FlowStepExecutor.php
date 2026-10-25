<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepResult;

interface FlowStepExecutor
{
    public function supports(FlowStepDefinition $step): bool;

    /**
     * Performs one host-approved operation for a single step attempt.
     *
     * The invocation carries the step definition, the resolved input
     * bindings (FlowStepInput), the run context, the previous step state,
     * the flow/run identity, and the 1-based attempt number. Resolved input
     * is only transported through `$invocation->input`.
     */
    public function execute(FlowStepInvocation $invocation): StepResult;
}
