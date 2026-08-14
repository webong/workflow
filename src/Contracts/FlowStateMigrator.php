<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowState;

interface FlowStateMigrator
{
    public function fromVersion(): int;

    public function toVersion(): int;

    public function migrate(FlowState $state): FlowState;
}
