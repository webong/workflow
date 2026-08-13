<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

use Zorvia\WebFlow\ValueObjects\FlowPresentation;
use Zorvia\WebFlow\ValueObjects\FlowState;

interface FlowPresentationResolver
{
    public function resolve(FlowState $state): ?FlowPresentation;
}
