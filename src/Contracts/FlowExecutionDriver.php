<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowExecutionReceipt;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;

interface FlowExecutionDriver
{
    public function name(): string;

    public function dispatch(FlowExecutionRequest $request): FlowExecutionReceipt;
}
