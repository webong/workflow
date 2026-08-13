<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use Zorvia\WebFlow\Contracts\FlowEventSink;
use Zorvia\WebFlow\ValueObjects\FlowEvent;

final class NullFlowEventSink implements FlowEventSink
{
    public function record(FlowEvent $event): void
    {
    }
}
