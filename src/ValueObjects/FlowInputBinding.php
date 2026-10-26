<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;
use Webong\WorkFlow\Enums\FlowInputSource;

/**
 * One declarative input binding of a FlowStepDefinition.
 *
 * A binding names a value in the resolved FlowStepInput and selects it from
 * either the run context or the first-class StepOutput of a step the owning
 * step already declares in `dependsOn`. Paths are RFC 6901 JSON Pointers;
 * the empty pointer selects the whole source document. References to steps
 * outside `dependsOn` are rejected by FlowDefinition validation.
 *
 * The binding representation stays transport-neutral: no expression
 * language, PHP callables, or host adapters live here.
 */
final readonly class FlowInputBinding
{
    public function __construct(
        public FlowInputSource $source,
        public string $pointer = '',
        public ?string $step = null,
        public bool $required = true,
    ) {
        if ($this->source === FlowInputSource::OUTPUT && ($this->step === null || $this->step === '')) {
            throw new InvalidArgumentException('An output input binding must name its source step.');
        }

        if ($this->source === FlowInputSource::CONTEXT && $this->step !== null) {
            throw new InvalidArgumentException('A context input binding cannot reference a step.');
        }

        if ($this->pointer !== ''
            && (! str_starts_with($this->pointer, '/') || preg_match('/~(?:[^01]|$)/', $this->pointer) === 1)) {
            throw new InvalidArgumentException("Invalid RFC 6901 JSON Pointer '{$this->pointer}'.");
        }
    }

    /** Reads from the run context. */
    public static function context(string $pointer = '', bool $required = true): self
    {
        return new self(FlowInputSource::CONTEXT, $pointer, null, $required);
    }

    /** Reads from the first-class output of a declared dependency. */
    public static function output(string $step, string $pointer = '', bool $required = true): self
    {
        return new self(FlowInputSource::OUTPUT, $pointer, $step, $required);
    }

    /** @param mixed $data */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw new InvalidArgumentException('A flow input binding must be an object.');
        }

        $source = $data['source'] ?? null;
        $parsedSource = is_string($source) ? FlowInputSource::tryFrom($source) : null;
        if ($parsedSource === null) {
            throw new InvalidArgumentException("A flow input binding source must be 'context' or 'output'.");
        }

        $pointer = $data['pointer'] ?? '';
        $step = $data['step'] ?? null;
        $required = $data['required'] ?? true;

        return new self(
            source: $parsedSource,
            pointer: is_string($pointer) ? $pointer : '',
            step: is_string($step) && $step !== '' ? $step : null,
            required: (bool) $required,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'pointer' => $this->pointer,
            'step' => $this->step,
            'required' => $this->required,
        ];
    }

    /** Human-safe binding description for diagnostics; never includes values. */
    public function describe(): string
    {
        return $this->source === FlowInputSource::CONTEXT
            ? "context pointer '{$this->pointer}'"
            : "output of step '{$this->step}' pointer '{$this->pointer}'";
    }
}
