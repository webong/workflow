<?php

declare(strict_types=1);

namespace Webong\WebFlow\Contracts;

use Webong\WebFlow\ValueObjects\FlowDeferredCompletion;
use Webong\WebFlow\ValueObjects\FlowDefinition;
use Webong\WebFlow\ValueObjects\FlowState;

interface FlowDeferredCompletionHandler
{
    public function complete(
        FlowDefinition $definition,
        FlowState $state,
        FlowDeferredCompletion $completion,
    ): FlowState;
}
