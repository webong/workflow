<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Temporal;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

/**
 * Temporal SDK activity contract for host-provided WorkFlow executors.
 *
 * The Temporal SDK is intentionally optional. This class is only used when
 * temporal/sdk has been installed by the host application.
 */
#[ActivityInterface]
interface TemporalFlowActivityInterface
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    #[ActivityMethod]
    public function execute(array $input): array;
}
