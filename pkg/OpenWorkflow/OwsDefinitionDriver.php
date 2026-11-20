<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow;

use Webong\WorkFlow\Contracts\FlowDefinitionDriver;
use Webong\WorkFlow\Enums\FlowDiagnosticSeverity;
use Webong\WorkFlow\ValueObjects\FlowDefinitionImportResult;
use Webong\WorkFlow\ValueObjects\FlowDiagnostic;

/** Supplies OWS import to core while preserving the legacy capability-report interface. */
final readonly class OwsDefinitionDriver implements FlowDefinitionDriver
{
    public function __construct(private OpenWorkflow $engine = new OpenWorkflow()) {}

    public function format(): string { return 'ows'; }

    /** Core definition-driver hook. Unsupported capabilities never expose a runnable partial definition. */
    public function import(string $document): FlowDefinitionImportResult
    {
        $report = $this->engine->registerOwsDefinition($document);
        $diagnostics = [];
        foreach ($report->unsupported as $feature) {
            $diagnostics[] = new FlowDiagnostic(
                $report->valid ? 'unsupported_definition_feature' : 'invalid_definition_document',
                $feature->reason,
                path: $feature->path,
                details: ['feature' => $feature->feature],
            );
        }
        if (! $report->valid && $diagnostics === []) {
            $diagnostics[] = new FlowDiagnostic('invalid_definition_document', 'The definition document is invalid.');
        }
        foreach ($report->warnings as $warning) {
            $diagnostics[] = new FlowDiagnostic('definition_import_warning', $warning, FlowDiagnosticSeverity::WARNING);
        }
        return new FlowDefinitionImportResult(
            $report->valid && $report->unsupported === [] ? $report->flowDefinition : null,
            $diagnostics,
            ['format' => $this->format(), 'specification_version' => $report->version, 'supported' => $report->supported] + $report->metadata,
        );
    }
}
