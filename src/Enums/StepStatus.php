<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Enums;

enum StepStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
