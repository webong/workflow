<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Laravel\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Spatie\EloquentSortable\SortableTrait;
use Webong\WorkFlow\Laravel\Models\WorkflowDefinitionRecord;
use Webong\WorkFlow\Laravel\Models\WorkflowStepDefinitionRecord;

/**
 * Adds dependency-aware ordering to persisted workflow steps.
 *
 * The underlying order mutations are provided by Spatie's sortable trait;
 * this trait validates every WorkFlow-scoped reorder before it is persisted.
 *
 * @property int $workflow_definition_id
 */
trait WorkflowSortable
{
    use SortableTrait {
        setNewOrder as private sortableSetNewOrder;
        moveOrderDown as private sortableMoveOrderDown;
        moveOrderUp as private sortableMoveOrderUp;
        moveToEnd as private sortableMoveToEnd;
        moveToStart as private sortableMoveToStart;
    }

    /** @return BelongsTo<WorkflowDefinitionRecord, $this> */
    abstract public function definition(): BelongsTo;

    public function moveOrderDown(): static
    {
        $siblings = $this->orderedSiblings();
        $index = $this->indexIn($siblings);

        if ($index !== null && $index < count($siblings) - 1) {
            [$siblings[$index], $siblings[$index + 1]] = [$siblings[$index + 1], $siblings[$index]];
            $this->assertValidOrder($siblings);
        }

        return $this->sortableMoveOrderDown();
    }

    public function moveOrderUp(): static
    {
        $siblings = $this->orderedSiblings();
        $index = $this->indexIn($siblings);

        if ($index !== null && $index > 0) {
            [$siblings[$index], $siblings[$index - 1]] = [$siblings[$index - 1], $siblings[$index]];
            $this->assertValidOrder($siblings);
        }

        return $this->sortableMoveOrderUp();
    }

    public function moveToEnd(): static
    {
        $siblings = $this->orderedSiblings();
        $index = $this->indexIn($siblings);

        if ($index !== null && $index < count($siblings) - 1) {
            $step = $siblings[$index];
            array_splice($siblings, $index, 1);
            $siblings[] = $step;
            $this->assertValidOrder($siblings);
        }

        return $this->sortableMoveToEnd();
    }

    public function moveToStart(): static
    {
        $siblings = $this->orderedSiblings();
        $index = $this->indexIn($siblings);

        if ($index !== null && $index > 0) {
            $step = $siblings[$index];
            array_splice($siblings, $index, 1);
            array_unshift($siblings, $step);
            $this->assertValidOrder($siblings);
        }

        return $this->sortableMoveToStart();
    }

    /**
     * @param array<int, int|string> $ids
     */
    public static function setNewOrderForDefinition(
        int|string $definitionId,
        array $ids,
        int $startOrder = 1,
    ): void {
        $definition = WorkflowDefinitionRecord::query()->find($definitionId);

        if ($definition === null) {
            throw new InvalidArgumentException("Workflow definition [{$definitionId}] was not found.");
        }

        $siblings = $definition->orderedSteps()->all();

        if ($siblings === []) {
            if ($ids !== []) {
                throw new InvalidArgumentException('The supplied workflow steps do not belong to this definition.');
            }

            return;
        }

        $byId = [];

        foreach ($siblings as $sibling) {
            $byId[(string) $sibling->getKey()] = $sibling;
        }

        $ordered = [];
        $seen = [];

        foreach ($ids as $id) {
            $key = (string) $id;

            if (! isset($byId[$key])) {
                throw new InvalidArgumentException("Workflow step [{$id}] does not belong to this definition.");
            }

            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Workflow step [{$id}] was supplied more than once.");
            }

            $ordered[] = $byId[$key];
            $seen[$key] = true;
        }

        foreach ($siblings as $sibling) {
            if (! isset($seen[(string) $sibling->getKey()])) {
                $ordered[] = $sibling;
            }
        }

        $definition->validateStepOrder(new Collection($ordered));

        self::sortableSetNewOrder(
            ids: $ids,
            startOrder: $startOrder,
            modifyQuery: static fn (Builder $query): Builder => $query->where(
                'workflow_definition_id',
                $definitionId,
            ),
        );
    }

    /** @return Builder<static> */
    public function buildSortQuery(): Builder
    {
        return static::query()->where('workflow_definition_id', $this->workflow_definition_id);
    }

    /** @return array<int, WorkflowStepDefinitionRecord> */
    private function orderedSiblings(): array
    {
        return $this->buildSortQuery()->get()->sortBy('order_column')->values()->all();
    }

    /** @param array<int, WorkflowStepDefinitionRecord> $siblings */
    private function indexIn(array $siblings): ?int
    {
        foreach ($siblings as $index => $sibling) {
            if ($sibling->getKey() === $this->getKey()) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<int, WorkflowStepDefinitionRecord> $ordered */
    private function assertValidOrder(array $ordered): void
    {
        $definition = $this->definition()->first();

        if ($definition === null) {
            throw new InvalidArgumentException('A workflow step must belong to a persisted definition.');
        }

        $definition->validateStepOrder(new Collection($ordered));
    }
}
