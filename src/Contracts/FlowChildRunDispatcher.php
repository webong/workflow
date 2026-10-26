<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowChildRunReceipt;
use Webong\WorkFlow\ValueObjects\FlowChildRunRequest;

/** Host-owned boundary for starting a child flow run. */
interface FlowChildRunDispatcher
{
    public function dispatch(FlowChildRunRequest $request): FlowChildRunReceipt;
}
