<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

/** A canonical definition is usable only when its import has no blocking diagnostics. */
final readonly class FlowDefinitionImportResult
{
    public ?FlowDefinition $definition;

    /** @var list<FlowDiagnostic> */
    public array $diagnostics;

    /**
     * @param list<FlowDiagnostic> $diagnostics
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        ?FlowDefinition $definition,
        array $diagnostics = [],
        public array $metadata = [],
    ) {
        if ($definition === null && (new FlowValidationReport($diagnostics))->isValid()) {
            $diagnostics[] = new FlowDiagnostic('definition_import_failed', 'The driver did not produce a usable definition.');
        }
        $this->diagnostics = $diagnostics;
        $this->definition = (new FlowValidationReport($diagnostics))->isValid() ? $definition : null;
    }

    public function isValid(): bool
    {
        return $this->definition !== null && (new FlowValidationReport($this->diagnostics))->isValid();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'flow_definition' => $this->definition?->toArray(),
            'diagnostics' => array_map(static fn (FlowDiagnostic $diagnostic): array => $diagnostic->toArray(), $this->diagnostics),
            'metadata' => $this->metadata,
        ];
    }
}
