<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

use Zorvia\WebFlow\ValueObjects\FlowState;

interface FlowStateStore
{
    public function get(string $flowKey): ?FlowState;

    public function put(string $flowKey, FlowState $state): void;
}
