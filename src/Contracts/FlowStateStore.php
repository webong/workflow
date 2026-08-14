<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowState;

interface FlowStateStore
{
    public function get(string $flowKey): ?FlowState;

    public function put(string $flowKey, FlowState $state): void;
}
