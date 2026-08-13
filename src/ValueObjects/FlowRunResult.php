<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\ValueObjects;

final readonly class FlowRunResult
{
    /** @param list<string> $executedSteps */
    public function __construct(
        public FlowState $state,
        public array $executedSteps = [],
    ) {
    }
}
