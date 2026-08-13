<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use RuntimeException;
use Zorvia\WebFlow\Contracts\FlowActionAuthorizer;
use Zorvia\WebFlow\Contracts\FlowActionHandler;
use Zorvia\WebFlow\Contracts\FlowActionRegistry;
use Zorvia\WebFlow\ValueObjects\FlowAction;

final class FlowActionDispatcher
{
    public function __construct(
        private readonly FlowActionRegistry $registry,
        private readonly ?FlowActionAuthorizer $authorizer = null,
    )
    {
    }

    /** @param array<string, mixed> $context */
    public function dispatch(FlowAction $action, array $context = []): mixed
    {
        if (! $action->enabled) {
            throw new RuntimeException("Flow action '{$action->key}' is disabled.");
        }

        if ($this->authorizer instanceof FlowActionAuthorizer && ! $this->authorizer->allows($action, $context)) {
            throw new RuntimeException("Flow action '{$action->key}' is not authorized.");
        }

        $handler = $this->registry->handlerFor($action);

        if (! $handler instanceof FlowActionHandler) {
            throw new RuntimeException("No handler registered for flow action '{$action->key}'.");
        }

        return $handler->handle($action, $context);
    }
}
