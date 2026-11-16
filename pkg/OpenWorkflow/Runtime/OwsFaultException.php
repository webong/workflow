<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

/**
 * A task fault carrying the OWS error properties (type, status, title).
 * Thrown by host executors (or built-in ones) inside try scopes so the fault
 * recorder can route it to the matching catch handler.
 */
final class OwsFaultException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorType,
        public readonly int $errorStatus,
        public readonly string $errorTitle,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $errorTitle);
    }

    /** @return array<string, mixed> */
    public function toFault(string $instance): array
    {
        return [
            'status' => $this->errorStatus,
            'type' => $this->errorType,
            'title' => $this->errorTitle,
            'instance' => $instance,
        ];
    }
}