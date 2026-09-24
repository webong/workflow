<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Throwable;
use Webong\WorkFlow\Contracts\FlowFailureReporter;

final class NullFlowFailureReporter implements FlowFailureReporter
{
    public function report(Throwable $exception, string $correlationId, string $flowKey, string $stepId, ?string $runId): void
    {
    }
}
