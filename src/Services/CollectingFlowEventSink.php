<?php

declare(strict_types=1);

namespace Webong\WebFlow\Services;

use Webong\WebFlow\Contracts\FlowEventSink;
use Webong\WebFlow\ValueObjects\FlowEvent;

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
