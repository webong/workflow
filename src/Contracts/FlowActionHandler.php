<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

use Zorvia\WebFlow\ValueObjects\FlowAction;

interface FlowActionHandler
{
    public function supports(FlowAction $action): bool;

    /** @param array<string, mixed> $context */
    public function handle(FlowAction $action, array $context = []): mixed;
}
