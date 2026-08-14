<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Webong\WorkFlow\Contracts\FlowEventSink;
use Webong\WorkFlow\ValueObjects\FlowEvent;

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
