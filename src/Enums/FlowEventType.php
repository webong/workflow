<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Enums;

enum FlowEventType: string
{
    case STARTED = 'started';
    case STEP_STARTED = 'step_started';
    case STEP_COMPLETED = 'step_completed';
    case STEP_FAILED = 'step_failed';
    case STEP_SKIPPED = 'step_skipped';
    case STEP_DEFERRED = 'step_deferred';
    case COMPLETED = 'completed';
    case ATTENTION_REQUIRED = 'attention_required';
    case BLOCKED = 'blocked';
    case RESET = 'reset';
}
