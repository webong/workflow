<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

use Zorvia\WebFlow\ValueObjects\FlowState;

interface FlowStateMigrator
{
    public function fromVersion(): int;

    public function toVersion(): int;

    public function migrate(FlowState $state): FlowState;
}
