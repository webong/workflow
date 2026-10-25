<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowEventSink;
use Webong\WorkFlow\Enums\FlowEventType;
use Webong\WorkFlow\Enums\FlowInputSource;
use Webong\WorkFlow\ValueObjects\FlowEvent;
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
 *
 * Resolution outcomes are reported to the caller's event sink without
 * values: events carry the binding name, its source description, and the
 * outcome — never the resolved data, which may hold credentials or
 * customer content (see ADR 0009).
 */
final readonly class FlowInputResolver
{
    public function resolve(
        FlowEventSink $events,
        string $flowKey,
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

            [$found, $value] = $this->resolveBinding($binding, $state, $contextDocument);

            if ($found) {
                $values[$name] = $value;
                $this->record($events, $flowKey, $step, $name, $binding, 'resolved');
                continue;
            }

            if ($binding->required) {
                $this->record($events, $flowKey, $step, $name, $binding, 'failed');
                throw new InvalidArgumentException(sprintf(
                    "Flow step '%s' has unresolved required input '%s' from %s.",
                    $step->id,
                    $name,
                    $binding->describe(),
                ));
            }

            $this->record($events, $flowKey, $step, $name, $binding, 'omitted');
        }

        return new FlowStepInput($values);
    }

    /**
     * @param array<string, mixed> $contextDocument
     * @return array{0: bool, 1: mixed}
     */
    private function resolveBinding(
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

    private function record(
        FlowEventSink $events,
        string $flowKey,
        FlowStepDefinition $step,
        string $name,
        FlowInputBinding $binding,
        string $outcome,
    ): void {
        $events->record(new FlowEvent(
            $outcome === 'resolved' ? FlowEventType::INPUT_RESOLVED : FlowEventType::INPUT_UNRESOLVED,
            $flowKey,
            $step->id,
            [
                'input' => $name,
                'source' => $binding->describe(),
                'required' => $binding->required,
                'outcome' => $outcome,
            ],
        ));
    }
}
