<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Webong\WorkFlow\Laravel\Concerns\HasWorkflowStates;

/**
 * @property array<string, mixed> $state
 * @property bool $initialized
 * @property int $schema_version
 * @property int $lock_version
 */
class WorkflowStateRecord extends Model
{
    use HasWorkflowStates;

    protected $table = 'workflow_states';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => 'array',
            'schema_version' => 'integer',
            'lock_version' => 'integer',
            'initialized' => 'boolean',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
