<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowOperation;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;

/**
 * Optional contract for executors that can describe their operation.
 *
 * Implement it alongside FlowStepExecutor. Describing an operation never
 * grants execution rights: supports() and execute() remain the only path
 * to run anything, and catalogs built from this contract are authoring
 * and documentation data.
 */
interface FlowOperationProvider
{
    /** The operation this executor would perform for the step, or null. */
    public function operation(FlowStepDefinition $step): ?FlowOperation;
}
