<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Closure;
use Webong\WorkFlow\ValueObjects\FlowState;

interface AtomicFlowStateStore extends FlowStateStore
{
    /**
     * @param Closure(?FlowState): FlowState $transition
     */
    public function mutate(string $flowKey, Closure $transition): FlowState;
}
