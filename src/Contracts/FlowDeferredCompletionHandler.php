<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowState;

interface FlowDeferredCompletionHandler
{
    public function complete(
        FlowDefinition $definition,
        FlowState $state,
        FlowDeferredCompletion $completion,
    ): FlowState;
}
