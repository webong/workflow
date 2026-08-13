<?php

declare(strict_types=1);

namespace Webong\WebFlow\Contracts;

use Webong\WebFlow\ValueObjects\FlowPresentation;
use Webong\WebFlow\ValueObjects\FlowState;

interface FlowPresentationResolver
{
    public function resolve(FlowState $state): ?FlowPresentation;
}
