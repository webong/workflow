<?php

declare(strict_types=1);

namespace Webong\WebFlow\ValueObjects;

final readonly class FlowRunResult
{
    /**
     * @param list<string> $executedSteps
     * @param list<FlowEvent> $events
     */
    public function __construct(
        public FlowState $state,
        public array $executedSteps = [],
        public array $events = [],
    ) {
    }
}
