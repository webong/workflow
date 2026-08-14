<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowDefinition;

interface FlowDefinitionProvider
{
    public function definition(string $flowKey): FlowDefinition;
}
