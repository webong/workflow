<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Enums;

enum FlowExecutionStatus: string
{
    case DISPATCHED = 'dispatched';
    case COMPLETED = 'completed';
}
