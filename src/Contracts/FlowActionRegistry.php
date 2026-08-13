<?php

declare(strict_types=1);

namespace Webong\WebFlow\Contracts;

use Webong\WebFlow\ValueObjects\FlowAction;

interface FlowActionRegistry
{
    public function handlerFor(FlowAction $action): ?FlowActionHandler;
}
