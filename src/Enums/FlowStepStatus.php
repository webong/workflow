<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Enums;

enum FlowStepStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
