<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Throwable;

/** Receives private diagnostics. Exception details must not be copied into public state. */
interface FlowFailureReporter
{
    public function report(Throwable $exception, string $correlationId, string $flowKey, string $stepId, ?string $runId): void;
}
