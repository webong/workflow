<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowAction;

interface FlowActionAuthorizer
{
    /** @param array<string, mixed> $context */
    public function allows(FlowAction $action, array $context = []): bool;
}
