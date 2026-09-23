<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Mod;

use RuntimeException;

final class RpcFailure extends RuntimeException
{
    public function __construct(
        public readonly int $rpcCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
