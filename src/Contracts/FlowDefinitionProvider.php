<?php

declare(strict_types=1);

namespace Zorvia\WebFlow\Contracts;

use Zorvia\WebFlow\ValueObjects\FlowDefinition;

interface FlowDefinitionProvider
{
    public function definition(string $flowKey): FlowDefinition;
}
