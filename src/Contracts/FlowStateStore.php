<?php

declare(strict_types=1);

namespace Webong\WebFlow\Contracts;

use Webong\WebFlow\ValueObjects\FlowState;

interface FlowStateStore
{
    public function get(string $flowKey): ?FlowState;

    public function put(string $flowKey, FlowState $state): void;
}
