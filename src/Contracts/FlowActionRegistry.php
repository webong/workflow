<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowAction;

interface FlowActionRegistry
{
    public function handlerFor(FlowAction $action): ?FlowActionHandler;
}
