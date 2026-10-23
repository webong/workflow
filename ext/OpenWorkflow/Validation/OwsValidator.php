<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Validation;

use Webong\WorkFlow\OpenWorkflow\ValueObjects\OwsDocument;

/**
 * Validates OWS documents with basic structural checks.
 * Full JSON Schema validation (DSL 1.0.3) is available but very strict;
 * this validator performs pragmatic checks for the supported subset.
 */
final class OwsValidator
{
    private const PINNED_VERSION = '1.0.3';

    public function __construct(
        private readonly string $pinnedVersion = self::PINNED_VERSION,
    ) {}

    /**
     * Validate an OWS document with basic structural checks.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validate(OwsDocument $document): array
    {
        $errors = [];
        $warnings = [];

        // Check required top-level fields
        $data = $document->all();

        if (! isset($data['document']) || ! is_array($data['document'])) {
            $errors[] = 'Missing or invalid "document" field';
        }

        if (! isset($data['do']) || ! is_array($data['do'])) {
            $errors[] = 'Missing or invalid "do" field (must be an array)';
        }

        // Check document fields
        $doc = $document->document();
        
        // Required document fields
        if (! isset($doc['namespace']) || $doc['namespace'] === '') {
            $errors[] = 'document.namespace: Required field is missing or empty';
        } elseif (! $this->isValidNamespace($doc['namespace'])) {
            $errors[] = 'document.namespace: Invalid namespace format';
        }

        if (! isset($doc['name']) || $doc['name'] === '') {
            $errors[] = 'document.name: Required field is missing or empty';
        } elseif (! $this->isValidName($doc['name'])) {
            $errors[] = 'document.name: Invalid name format';
        }

        if (! isset($doc['version']) || $doc['version'] === '') {
            $errors[] = 'document.version: Required field is missing or empty';
        } elseif (! $this->isValidSemver($doc['version'])) {
            $warnings[] = 'document.version: Should be a valid semantic version';
        }

        // Check DSL version
        $docVersion = $document->version();
        if ($docVersion !== $this->pinnedVersion) {
            $warnings[] = "Document declares DSL version '{$docVersion}', but validator is pinned to '{$this->pinnedVersion}'";
        }

        // Check for deprecated fields (Serverless Workflow naming)
        if (isset($doc['dsl'])) {
            $warnings[] = "Field 'document.dsl' is deprecated; use top-level 'version' instead";
        }

        // Validate each task in do array
        $tasks = $document->do();
        foreach ($tasks as $index => $task) {
            $this->validateTask($task, $index, $errors, $warnings, "/do/{$index}");
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateTask(array $task, int $index, array &$errors, array &$warnings, string $path): void
    {
        if (! isset($task['name']) || ! is_string($task['name']) || $task['name'] === '') {
            $errors[] = "{$path}: Task must have a 'name' field";
            return;
        }

        // Check for unsupported task types
        $unsupportedTypes = ['for', 'fork', 'wait', 'listen', 'try', 'raise', 'emit'];
        foreach ($unsupportedTypes as $type) {
            if (isset($task[$type])) {
                $warnings[] = "{$path}/{$type}: '{$type}' is not supported in initial subset";
            }
        }

        // `set` is supported: its value must be an object of key => jq expression
        if (isset($task['set']) && ! is_array($task['set'])) {
            $errors[] = "{$path}/set: Must be an object of key => expression pairs";
        }

        // Recurse into nested composite tasks so nested constructs are not
        // silently accepted.
        if (isset($task['do']) && is_array($task['do'])) {
            foreach (OwsDocument::normalizeTaskItems($task['do']) as $nestedIndex => $nestedTask) {
                $this->validateTask($nestedTask, $nestedIndex, $errors, $warnings, "{$path}/{$task['name']}/do/{$nestedIndex}");
            }
        }

        // Check switch - supported; OWS 1.0.3 switch is an object of named
        // cases (each with optional `when`/`then`), not a string expression.
        if (isset($task['switch']) && ! is_array($task['switch']) && ! is_string($task['switch'])) {
            $errors[] = "{$path}/switch: Must be an object of named cases or a string expression";
        }

        // Check call or run
        $hasCall = isset($task['call']) && is_string($task['call']);
        $hasRun = isset($task['run']);

        if (! $hasCall && ! $hasRun) {
            // Generic task with action is OK
            if (! isset($task['action']) && ! isset($task['do'])) {
                $warnings[] = "{$path}: Task has no 'call', 'run', 'action', or 'do' field";
            }
        }

        // Validate retry policy if present
        if (isset($task['retry']) && is_array($task['retry'])) {
            $retry = $task['retry'];
            if (isset($retry['maxAttempts']) && (! is_int($retry['maxAttempts']) || $retry['maxAttempts'] < 1)) {
                $errors[] = "{$path}/retry/maxAttempts: Must be a positive integer";
            }
            if (isset($retry['backoff']) && (! is_int($retry['backoff']) || $retry['backoff'] < 0)) {
                $errors[] = "{$path}/retry/backoff: Must be a non-negative integer";
            }
        }

        // Validate dependsOn if present
        if (isset($task['dependsOn']) && is_array($task['dependsOn'])) {
            foreach ($task['dependsOn'] as $dep) {
                if (! is_string($dep)) {
                    $errors[] = "{$path}/dependsOn: All dependencies must be strings";
                }
            }
        }
    }

    private function isValidNamespace(string $namespace): bool
    {
        // OWS allows dots for hierarchical namespaces (e.g., com.example)
        return preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,61}[a-zA-Z0-9])?$/', $namespace) === 1;
    }

    private function isValidName(string $name): bool
    {
        return preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $name) === 1;
    }

    private function isValidSemver(string $version): bool
    {
        return preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/', $version) === 1;
    }

    public function pinnedVersion(): string
    {
        return $this->pinnedVersion;
    }
}