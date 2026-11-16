<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Webong\WorkFlow\ValueObjects\FlowDefinition;

/**
 * @property string $key
 * @property int $version
 * @property array<string, mixed>|null $metadata
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WorkflowStepDefinitionRecord> $steps
 */
class WorkflowDefinitionRecord extends Model
{
    protected $table = 'workflow_definitions';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<WorkflowStepDefinitionRecord, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStepDefinitionRecord::class, 'workflow_definition_id');
    }

    /** @return Collection<int, WorkflowStepDefinitionRecord> */
    public function orderedSteps(): Collection
    {
        $steps = $this->relationLoaded('steps')
            ? $this->steps
            : $this->steps()->get();

        return $steps->sortBy('order_column')->values();
    }

    /** @param Collection<int, WorkflowStepDefinitionRecord> $steps */
    public function validateStepOrder(Collection $steps): void
    {
        $positions = [];

        foreach ($steps as $position => $step) {
            $positions[$step->step_id] = $position;
        }

        foreach ($steps as $position => $step) {
            foreach ($step->depends_on ?? [] as $dependency) {
                if (isset($positions[$dependency]) && $positions[$dependency] > $position) {
                    throw new InvalidArgumentException(
                        "Workflow step '{$step->step_id}' must be ordered after its dependency '{$dependency}'.",
                    );
                }
            }
        }
    }

    public function toFlowDefinition(): FlowDefinition
    {
        $orderedSteps = $this->orderedSteps();
        $this->validateStepOrder($orderedSteps);

        $steps = $orderedSteps
            ->map(static fn (WorkflowStepDefinitionRecord $step): array => $step->toFlowStepDefinition()->toArray())
            ->all();

        return FlowDefinition::fromArray([
            'key' => $this->key,
            'version' => $this->version,
            'metadata' => $this->metadata ?? [],
            'steps' => $steps,
        ]);
    }
}
