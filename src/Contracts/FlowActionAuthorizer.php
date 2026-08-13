<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

use Zorvia\WebFlow\ValueObjects\FlowAction;

interface FlowActionAuthorizer
{
    /** @param array<string, mixed> $context */
    public function allows(FlowAction $action, array $context = []): bool;
}
