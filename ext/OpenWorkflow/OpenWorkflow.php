<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow;

use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\OpenWorkflow\Contracts\CapabilityReport;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\OpenWorkflow\Translation\OwsToFlowTranslator;
use Webong\WorkFlow\OpenWorkflow\Validation\OwsValidator;
use Webong\WorkFlow\OpenWorkflow\ValueObjects\OwsDocument;
use Webong\WorkFlow\ValueObjects\FlowDefinition;

/**
 * Main entry point for Open Workflow Specification support.
 *
 * Usage:
 * ```php
 * $openWorkflow = new OpenWorkflow();
 * $report = $openWorkflow->registerOwsDefinition($yamlOrJson);
 *
 * if ($report->valid && $report->flowDefinition !== null) {
 *     // Host decides whether to persist the definition
 *     $definitionProvider->save($report->flowDefinition);
 * }
 * ```
 */
final class OpenWorkflow
{
    private const PINNED_VERSION = '1.0.3';

    public function __construct(
        private readonly OwsValidator $validator = new OwsValidator(),
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
        private ?OwsToFlowTranslator $translator = null,
    ) {
        $this->translator = $translator ?? new OwsToFlowTranslator($this->expressionEvaluator);
    }

    /**
     * Validate and analyze an OWS document (JSON or YAML).
     * Returns a CapabilityReport with supported/unsupported features.
     *
     * Does NOT persist the definition - that remains host-controlled.
     */
    public function registerOwsDefinition(string $yamlOrJson): CapabilityReport
    {
        // Parse document
        $document = null;
        try {
            $document = OwsDocument::fromString($yamlOrJson);
        } catch (\Throwable $e) {
            return CapabilityReport::invalid(self::PINNED_VERSION, [
                'Failed to parse document: ' . $e->getMessage(),
            ]);
        }

        // Validate against pinned schema
        $validation = $this->validator->validate($document);
        if (! $validation['valid']) {
            return CapabilityReport::invalid($this->validator->pinnedVersion(), $validation['errors']);
        }

        // Analyze expressions in the document for unsupported features
        $expressionIssues = $this->analyzeExpressions($document);
        $unsupported = array_merge(
            array_map(
                static fn (array $issue): \Webong\WorkFlow\OpenWorkflow\Contracts\UnsupportedFeature =>
                    new \Webong\WorkFlow\OpenWorkflow\Contracts\UnsupportedFeature(
                        feature: 'expression',
                        path: $issue['path'],
                        reason: $issue['reason'],
                    ),
                $expressionIssues,
            ),
        );

        // Translate to FlowDefinition
        $translation = $this->translator->translate($document);

        // Merge unsupported from translation
        $unsupported = array_merge($unsupported, $translation['unsupported']);

        // Check if we have any unsupported features that would break execution
        $hasBlockingUnsupported = count($unsupported) > 0;

        $warnings = array_merge($validation['warnings'], $translation['warnings']);

        if ($hasBlockingUnsupported) {
            return CapabilityReport::valid(
                version: $this->validator->pinnedVersion(),
                supported: $translation['supported'],
                unsupported: $unsupported,
                warnings: $warnings,
                flowDefinition: $translation['flowDefinition'],
                metadata: [
                    'ows_namespace' => $document->namespace(),
                    'ows_name' => $document->name(),
                    'ows_version' => $document->semver(),
                    'composite_key' => $document->compositeKey(),
                    'blocking_unsupported' => true,
                ],
            );
        }

        return CapabilityReport::valid(
            version: $this->validator->pinnedVersion(),
            supported: $translation['supported'],
            unsupported: $unsupported,
            warnings: $warnings,
            flowDefinition: $translation['flowDefinition'],
            metadata: [
                'ows_namespace' => $document->namespace(),
                'ows_name' => $document->name(),
                'ows_version' => $document->semver(),
                'composite_key' => $document->compositeKey(),
                'blocking_unsupported' => false,
            ],
        );
    }

    /**
     * Validate an OWS document without translating.
     * Useful for pre-flight checks.
     */
    public function validateOnly(string $yamlOrJson): CapabilityReport
    {
        $document = null;
        try {
            $document = OwsDocument::fromString($yamlOrJson);
        } catch (\Throwable $e) {
            return CapabilityReport::invalid(self::PINNED_VERSION, [
                'Failed to parse document: ' . $e->getMessage(),
            ]);
        }

        $validation = $this->validator->validate($document);
        if (! $validation['valid']) {
            return CapabilityReport::invalid($this->validator->pinnedVersion(), $validation['errors']);
        }

        $expressionIssues = $this->analyzeExpressions($document);
        $unsupported = array_map(
            static fn (array $issue): \Webong\WorkFlow\OpenWorkflow\Contracts\UnsupportedFeature =>
                new \Webong\WorkFlow\OpenWorkflow\Contracts\UnsupportedFeature(
                    feature: 'expression',
                    path: $issue['path'],
                    reason: $issue['reason'],
                ),
            $expressionIssues,
        );

        return CapabilityReport::valid(
            version: $this->validator->pinnedVersion(),
            supported: $this->translatorSupportedFeatures(),
            unsupported: $unsupported,
            warnings: $validation['warnings'],
            metadata: [
                'ows_namespace' => $document->namespace(),
                'ows_name' => $document->name(),
                'ows_version' => $document->semver(),
                'composite_key' => $document->compositeKey(),
            ],
        );
    }

    /**
     * Evaluate an expression in the context of a workflow run.
     * Used by execution drivers that need to evaluate OWS expressions at runtime.
     *
     * @param array<string, mixed> $workflowData
     * @param array<string, mixed> $taskData
     * @return mixed
     */
    public function evaluateExpression(
        string $expression,
        array $workflowData = [],
        array $taskData = [],
        ?FlowContext $context = null,
    ): mixed {
        $evaluator = new ExpressionEvaluator($workflowData, $taskData, $context);
        return $evaluator->evaluate($expression);
    }

    /**
     * Check if an expression uses only supported features.
     *
     * @return array{supported: bool, unsupportedFeatures: list<string>}
     */
    public function analyzeExpression(string $expression): array
    {
        return $this->expressionEvaluator->analyze($expression);
    }

    public function pinnedVersion(): string
    {
        return $this->validator->pinnedVersion();
    }

    /**
     * @return list<array{path: string, reason: string}>
     */
    private function analyzeExpressions(OwsDocument $document): array
    {
        $issues = [];
        $this->analyzeDocumentExpressions($document, '', $issues);
        return $issues;
    }

    /**
     * @param list<array{path: string, reason: string}> $issues
     */
    private function analyzeDocumentExpressions(OwsDocument $document, string $prefix, array &$issues): void
    {
        $data = $document->all();

        // Check expressions in known locations
        $expressionPaths = [
            'input' => '/input',
            'output' => '/output',
            'do' => '/do',
        ];

        foreach ($expressionPaths as $key => $path) {
            if (isset($data[$key])) {
                $this->analyzeValueExpressions($data[$key], $prefix . $path, $issues);
            }
        }

        // Check document-level expressions
        $doc = $document->document();
        if (isset($doc['input'])) {
            $this->analyzeValueExpressions($doc['input'], $prefix . '/document/input', $issues);
        }
        if (isset($doc['output'])) {
            $this->analyzeValueExpressions($doc['output'], $prefix . '/document/output', $issues);
        }
    }

    /**
     * @param list<array{path: string, reason: string}> $issues
     */
    private function analyzeValueExpressions(mixed $value, string $path, array &$issues): void
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            // OWS runtime expressions are wrapped in `${ ... }`; analyze the
            // inner jq expression. Simple `{placeholder}` interpolation in
            // strings (e.g. URI templates) is plain data, not a jq expression.
            if (preg_match('/^\$\{(.*)\}$/s', $trimmed, $m) === 1) {
                $this->recordIfUnsupported(trim($m[1]), $path, 'jq runtime expression', $issues);
                return;
            }

            // Expression-holding fields per the OWS DSL hold a jq expression
            // directly (no ${} wrapper): input.from, output.as, export.as,
            // and switch-case `when` conditions.
            $lastSegment = substr($path, (int) strrpos($path, '/') + 1);
            if (in_array($lastSegment, ['as', 'from', 'when'], true)) {
                $this->recordIfUnsupported($trimmed, $path, 'jq expression', $issues);
            }
            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $itemPath = "{$path}/{$key}";
                $this->analyzeValueExpressions($item, $itemPath, $issues);
            }
        }
    }

    /**
     * @param list<array{path: string, reason: string}> $issues
     */
    private function recordIfUnsupported(string $jq, string $path, string $kind, array &$issues): void
    {
        if ($jq === '') {
            return;
        }
        $analysis = $this->expressionEvaluator->analyze($jq);
        if (! $analysis['supported']) {
            foreach ($analysis['unsupportedFeatures'] as $feature) {
                $issues[] = [
                    'path' => $path,
                    'reason' => "Unsupported {$kind} feature: {$feature}",
                ];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function translatorSupportedFeatures(): array
    {
        // Create a temporary translator to get the supported features list
        $tempTranslator = new OwsToFlowTranslator(new ExpressionEvaluator());
        // Use reflection to access the private property
        $reflection = new \ReflectionClass($tempTranslator);
        $property = $reflection->getProperty('supportedFeatures');
        $property->setAccessible(true);
        return $property->getValue($tempTranslator);
    }
}