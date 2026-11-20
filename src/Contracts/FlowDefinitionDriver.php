<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowDefinitionImportResult;

/** Translates a definition language without persisting, activating or executing it. */
interface FlowDefinitionDriver
{
    public function format(): string;

    public function import(string $document): FlowDefinitionImportResult;
}
