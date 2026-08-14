<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowAction;

interface FlowActionHandler
{
    public function supports(FlowAction $action): bool;

    /** @param array<string, mixed> $context */
    public function handle(FlowAction $action, array $context = []): mixed;
}
