<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Enums;

enum FlowStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case ATTENTION = 'attention';
    case COMPLETED = 'completed';
    case BLOCKED = 'blocked';
}
