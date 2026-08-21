<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\EloquentSortable\Sortable;
use Webong\WorkFlow\Laravel\Concerns\WorkflowSortable;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;

/**
 * @property int $workflow_definition_id
 * @property string $step_id
 * @property string $label
 * @property bool $critical
 * @property bool $retriable
 * @property list<string>|null $depends_on
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $retry_policy
 * @property int $order_column
 */
class WorkflowStepDefinitionRecord extends Model implements Sortable
{
    use WorkflowSortable;

    protected $table = 'workflow_definition_steps';

    protected $guarded = [];

    /** @var array<string, mixed> */
    public array $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'workflow_definition_id' => 'integer',
            'critical' => 'boolean',
            'retriable' => 'boolean',
            'depends_on' => 'array',
            'metadata' => 'array',
            'retry_policy' => 'array',
            'order_column' => 'integer',
        ];
    }

    /** @return BelongsTo<WorkflowDefinitionRecord, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinitionRecord::class, 'workflow_definition_id');
    }

    public function toFlowStepDefinition(): FlowStepDefinition
    {
        return FlowStepDefinition::fromArray([
            'id' => $this->step_id,
            'label' => $this->label,
            'critical' => $this->critical,
            'retriable' => $this->retriable,
            'depends_on' => $this->depends_on ?? [],
            'metadata' => $this->metadata ?? [],
            'retry_policy' => $this->retry_policy,
        ]);
    }
}
