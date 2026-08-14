<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowPresentation;
use Webong\WorkFlow\ValueObjects\FlowState;

interface FlowPresentationResolver
{
    public function resolve(FlowState $state): ?FlowPresentation;
}
