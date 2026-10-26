<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Contracts;

use Webong\WorkFlow\ValueObjects\FlowOperation;

/**
 * Optional contract for executors that can enumerate the operations they
 * perform without being handed a concrete step.
 *
 * `FlowOperationProvider` describes the operation for one step; a directory
 * is the discovery view an authoring surface needs before a definition
 * exists ("what can this host do?"). Both are description only: listing an
 * operation never grants execution rights, and `supports()` plus
 * `execute()` remain the only path to run anything.
 */
interface FlowOperationDirectory
{
    /** @return list<FlowOperation> */
    public function operations(): array;
}
