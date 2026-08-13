<?php

declare(strict_types=1);

namespace Webong\WebFlow\Contracts;

use Webong\WebFlow\ValueObjects\FlowAction;

interface FlowActionAuthorizer
{
    /** @param array<string, mixed> $context */
    public function allows(FlowAction $action, array $context = []): bool;
}
