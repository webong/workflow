<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use Webong\WorkFlow\Contracts\FlowStateSerializer;
use Webong\WorkFlow\ValueObjects\FlowState;

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
