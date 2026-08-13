<?php

declare(strict_types=1);

namespace Webong\WebFlow\Services;

use Webong\WebFlow\Contracts\FlowEventSink;
use Webong\WebFlow\ValueObjects\FlowEvent;

final class NullFlowEventSink implements FlowEventSink
{
    public function record(FlowEvent $event): void
    {
    }
}
