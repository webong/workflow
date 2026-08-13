<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

use Zorvia\WebFlow\ValueObjects\FlowState;

interface FlowStateSerializer
{
    /** @return array<string, mixed> */
    public function serialize(FlowState $state): array;

    /** @param array<string, mixed> $data */
    public function deserialize(array $data): FlowState;
}
