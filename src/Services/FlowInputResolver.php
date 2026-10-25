<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Enums\FlowInputSource;
use Webong\WorkFlow\ValueObjects\FlowInputBinding;
use Webong\WorkFlow\ValueObjects\FlowState;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInput;

/**
 * Resolves a step's declarative input bindings before its executor runs.
 *
 * Sources are the run context and the first-class output of a dependency
 * step. Required bindings that cannot resolve fail closed — the exception
 * leaves the run untouched and no executor is invoked, so an unresolved
 * value can never silently become a side effect. Optional unresolved
 * bindings are simply absent from the resolved input.
 */
final readonly class FlowInputResolver
{
    public function resolve(
        FlowStepDefinition $step,
        FlowState $state,
        FlowContext $context,
    ): FlowStepInput {
        if ($step->input === []) {
            return new FlowStepInput();
        }

        $contextDocument = $context->all();
        $values = [];

        foreach ($step->input as $name => $binding) {
            if (! $binding instanceof FlowInputBinding) {
                throw new InvalidArgumentException("Flow step '{$step->id}' has an invalid input binding '{$name}'.");
            }

            [$found, $value] = $this->resolveBinding($step, $binding, $state, $contextDocument);

            if ($found) {
                $values[$name] = $value;
                continue;
            }

            if ($binding->required) {
                throw new InvalidArgumentException(sprintf(
                    "Flow step '%s' has unresolved required input '%s' from %s.",
                    $step->id,
                    $name,
                    $binding->describe(),
                ));
            }
        }

        return new FlowStepInput($values);
    }

    /**
     * @param array<string, mixed> $contextDocument
     * @return array{0: bool, 1: mixed}
     */
    private function resolveBinding(
        FlowStepDefinition $step,
        FlowInputBinding $binding,
        FlowState $state,
        array $contextDocument,
    ): array {
        if ($binding->source === FlowInputSource::CONTEXT) {
            return JsonPointer::resolve($contextDocument, $binding->pointer);
        }

        $dependency = $state->steps[$binding->step ?? ''] ?? null;
        if ($dependency === null || $dependency->output === null) {
            return [false, null];
        }

        return JsonPointer::resolve($dependency->output->value, $binding->pointer);
    }
}
