<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

use Zorvia\WebFlow\ValueObjects\FlowEvent;

interface FlowEventSink
{
    public function record(FlowEvent $event): void;
}
