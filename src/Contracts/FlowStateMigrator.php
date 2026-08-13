<?php

declare(strict_types=1);

namespace Webong\WebFlow\Contracts;

use Webong\WebFlow\ValueObjects\FlowState;

interface FlowStateMigrator
{
    public function fromVersion(): int;

    public function toVersion(): int;

    public function migrate(FlowState $state): FlowState;
}
