<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Webong\WorkFlow\Contracts\FlowEventSink;
use Webong\WorkFlow\ValueObjects\FlowEvent;

final class NullFlowEventSink implements FlowEventSink
{
    public function record(FlowEvent $event): void
    {
    }
}
