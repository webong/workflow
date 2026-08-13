<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use Zorvia\WebFlow\Contracts\FlowEventSink;
use Zorvia\WebFlow\ValueObjects\FlowEvent;

final class CollectingFlowEventSink implements FlowEventSink
{
    /** @var list<FlowEvent> */
    private array $events = [];

    public function record(FlowEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<FlowEvent> */
    public function events(): array
    {
        return $this->events;
    }
}
