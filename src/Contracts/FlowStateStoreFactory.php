<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowStateSubject;

interface FlowStateStoreFactory
{
    public function for(FlowStateSubject $subject): ForgettableFlowStateStore;
}
