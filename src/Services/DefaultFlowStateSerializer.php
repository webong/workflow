<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Services;

use Zorvia\WebFlow\Contracts\FlowStateSerializer;
use Zorvia\WebFlow\ValueObjects\FlowState;

final class DefaultFlowStateSerializer implements FlowStateSerializer
{
    public function serialize(FlowState $state): array
    {
        return $state->toArray();
    }

    public function deserialize(array $data): FlowState
    {
        return FlowState::fromArray($data);
    }
}
