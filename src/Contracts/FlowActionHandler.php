<?php

declare(strict_types=1);

namespace Webong\WebFlow\Contracts;

use Webong\WebFlow\ValueObjects\FlowAction;

interface FlowActionHandler
{
    public function supports(FlowAction $action): bool;

    /** @param array<string, mixed> $context */
    public function handle(FlowAction $action, array $context = []): mixed;
}
