<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Enums;

/** Where a declarative input binding reads its value from. */
enum FlowInputSource: string
{
    /** The run's host-supplied FlowContext. */
    case CONTEXT = 'context';

    /** The first-class StepOutput of a declared dependency step. */
    case OUTPUT = 'output';
}
