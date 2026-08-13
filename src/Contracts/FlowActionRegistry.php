<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

use Zorvia\WebFlow\ValueObjects\FlowAction;

interface FlowActionRegistry
{
    public function handlerFor(FlowAction $action): ?FlowActionHandler;
}
