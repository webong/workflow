<?php

declare(strict_types=1);

namespace Webong\WebFlow\Contracts;

use Webong\WebFlow\ValueObjects\FlowEvent;

interface FlowEventSink
{
    public function record(FlowEvent $event): void;
}
