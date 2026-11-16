<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Reads a step's structured output from persisted state.
 *
 * Since structured outputs became first-class (`StepState::output`), the
 * adapter also writes the value there in addition to the legacy
 * `ows_output` metadata. This helper prefers the first-class output and
 * falls back to the legacy metadata so states persisted by earlier adapter
 * versions keep feeding data flow and host projections.
 */
final class OwsOutputs
{
    /** Whether the step carries any recorded output (first-class or legacy). */
    public static function has(StepState $state): bool
    {
        return $state->output !== null || array_key_exists('ows_output', $state->metadata);
    }

    /**
     * The step's output value, or null when none was recorded. A first-class
     * JSON null and a legacy `ows_output: null` both read as null; use
     * `has()` to distinguish recorded-null from absent.
     */
    public static function fromStep(StepState $state): mixed
    {
        if ($state->output !== null) {
            return $state->output->value;
        }

        return array_key_exists('ows_output', $state->metadata)
            ? $state->metadata['ows_output']
            : null;
    }
}
