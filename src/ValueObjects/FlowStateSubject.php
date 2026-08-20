<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;

final readonly class FlowStateSubject
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if ($this->type === '' || $this->id === '') {
            throw new InvalidArgumentException('Flow state subjects require a type and id.');
        }
    }
}
