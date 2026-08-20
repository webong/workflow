<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

interface ForgettableFlowStateStore extends AtomicFlowStateStore
{
    public function forget(string $flowKey): void;
}
