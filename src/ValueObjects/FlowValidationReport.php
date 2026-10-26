<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;
use Webong\WorkFlow\Enums\FlowDiagnosticSeverity;

/**
 * The structured result of validating one flow definition document.
 *
 * A report is valid when it contains no `error` diagnostics; warnings and
 * informational findings never block authoring. The report travels as
 * plain JSON, so JSON-RPC clients, the CLI, and generated clients render
 * the same findings without reimplementing any rule.
 */
final readonly class FlowValidationReport
{
    /** @param list<FlowDiagnostic> $diagnostics */
    public function __construct(
        public array $diagnostics = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /** @return list<FlowDiagnostic> */
    public function errors(): array
    {
        return $this->ofSeverity(FlowDiagnosticSeverity::ERROR);
    }

    /** @return list<FlowDiagnostic> */
    public function warnings(): array
    {
        return $this->ofSeverity(FlowDiagnosticSeverity::WARNING);
    }

    /** @param mixed $data */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw new InvalidArgumentException('A flow validation report must be an object.');
        }

        $raw = $data['diagnostics'] ?? null;
        $diagnostics = [];
        if (is_array($raw)) {
            foreach ($raw as $diagnostic) {
                $diagnostics[] = FlowDiagnostic::fromArray($diagnostic);
            }
        }

        return new self($diagnostics);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'diagnostics' => array_map(
                static fn (FlowDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            ),
        ];
    }

    /** @return list<FlowDiagnostic> */
    private function ofSeverity(FlowDiagnosticSeverity $severity): array
    {
        $filtered = [];
        foreach ($this->diagnostics as $diagnostic) {
            if ($diagnostic->severity === $severity) {
                $filtered[] = $diagnostic;
            }
        }

        return $filtered;
    }
}
