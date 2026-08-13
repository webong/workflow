<?php

declare(strict_types=1);

namespace Webong\WebFlow\Contracts;

use Webong\WebFlow\ValueObjects\FlowDefinition;

interface FlowDefinitionProvider
{
    public function definition(string $flowKey): FlowDefinition;
}
