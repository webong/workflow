<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowEvent;

interface FlowEventSink
{
    public function record(FlowEvent $event): void;
}
