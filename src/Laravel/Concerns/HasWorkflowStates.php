<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Webong\WorkFlow\Laravel\Models\WorkflowStateRecord;

trait HasWorkflowStates
{
    /** @return MorphMany<WorkflowStateRecord, $this> */
    public function workflowStates(): MorphMany
    {
        return $this->morphMany(WorkflowStateRecord::class, 'subject');
    }
}
