<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;
use Webong\WorkFlow\Contracts\FlowOperationProvider;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Enums\FlowDiagnosticSeverity;
use Webong\WorkFlow\Enums\FlowInputSource;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowDiagnostic;
use Webong\WorkFlow\ValueObjects\FlowOperation;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowValidationReport;

/**
 * Static validation for a canonical flow definition document.
 *
 * The validator never executes, registers, or persists anything, and it
 * never throws for a bad document: it returns one FlowValidationReport with
 * structured diagnostics whose `path` is an RFC 6901 JSON Pointer into the
 * document and whose `code` is stable. JSON-RPC (`flow.validate`), the
 * `workflow` CLI, and host tooling all use this module, so no client
 * reimplements the rules.
 *
 * Checks, in the order an author can act on them:
 *
 * - document shape: key, version, steps, and step/input object shapes;
 * - topology: dependency targets, self-dependencies, and cycles;
 * - bindings: sources, JSON Pointers, and steps that are declared
 *   dependencies;
 * - operation availability: whether a bound executor supports each step;
 * - required capabilities: definition metadata and operation descriptors
 *   against the capabilities the host grants;
 * - optional schemas: required input properties and output pointers read
 *   from operation descriptors.
 *
 * Operation, capability, and schema checks need host executors. When none
 * are supplied the report says so (`operations_unchecked`) instead of
 * guessing. Nothing here is authoritative about runtime data: an unresolved
 * binding still fails closed in FlowInputResolver, and the core never
 * interprets schema values or the data that flows through them.
 */
final readonly class FlowDefinitionValidator
{
    /**
     * @param iterable<mixed> $executors Executors bound on this host; only
     *     FlowStepExecutor instances participate in operation checks.
     * @param list<string>|null $grantedCapabilities The capabilities the host
     *     grants. `null` disables capability checking, which is reported as a
     *     warning when the document declares requirements.
     */
    public function __construct(
        private iterable $executors = [],
        private ?array $grantedCapabilities = null,
    ) {
    }

    public function validate(mixed $definition): FlowValidationReport
    {
        $document = $this->document($definition);
        if ($document === null) {
            return new FlowValidationReport([
                $this->error('definition_not_object', 'A flow definition must be a JSON object.'),
            ]);
        }

        $executors = is_array($this->executors)
            ? array_values($this->executors)
            : iterator_to_array($this->executors, false);

        $diagnostics = [];

        $key = $document['key'] ?? null;
        if (! is_string($key) || $key === '') {
            $diagnostics[] = $this->error('missing_key', 'A flow definition requires a non-empty string key.', '/key');
        }

        $version = $document['version'] ?? null;
        if ($version !== null && (! is_int($version) || $version < 1)) {
            $diagnostics[] = $this->error('invalid_version', 'A flow version must be a positive integer.', '/version');
        }

        $stepsValue = $document['steps'] ?? null;
        if (! is_array($stepsValue)) {
            $diagnostics[] = $stepsValue === null
                ? $this->warning('no_steps', 'The flow definition declares no steps.', '/steps')
                : $this->error('invalid_steps', 'Flow steps must be a list of step objects.', '/steps');
            $stepsValue = [];
        } elseif ($stepsValue === []) {
            $diagnostics[] = $this->warning('no_steps', 'The flow definition declares no steps.', '/steps');
        }

        /** @var list<mixed> $rawSteps */
        $rawSteps = array_values($stepsValue);

        $idIndexes = $this->stepIds($rawSteps, $diagnostics);
        $dependencies = $this->dependencies($rawSteps, $idIndexes, $diagnostics);
        foreach ($this->cycleDiagnostics($dependencies, $idIndexes) as $diagnostic) {
            $diagnostics[] = $diagnostic;
        }
        $this->bindings($rawSteps, $idIndexes, $dependencies, $diagnostics);
        $operations = $this->operations($rawSteps, $executors, $diagnostics);
        $this->capabilities($document, $operations, $idIndexes, $diagnostics);
        $this->schemas($rawSteps, $operations, $idIndexes, $diagnostics);

        if ($executors === []) {
            $diagnostics[] = $this->warning(
                'operations_unchecked',
                'Operation availability, capabilities, and schemas were not checked: no executors were supplied.',
            );
        }

        // Backstop: the runtime rejects some documents this validator
        // diagnoses broadly. Never report valid for a document the core
        // refuses to construct.
        try {
            FlowDefinition::fromArray($document);
        } catch (InvalidArgumentException $exception) {
            if (! $this->hasError($diagnostics)) {
                $diagnostics[] = $this->error('invalid_definition', $exception->getMessage());
            }
        }

        return new FlowValidationReport($diagnostics);
    }

    /** @return array<string, mixed>|null Null when the value is not a JSON object. */
    private function document(mixed $definition): ?array
    {
        if ($definition instanceof FlowDefinition) {
            return $definition->toArray();
        }

        if (! is_array($definition)) {
            return null;
        }

        $document = [];
        foreach ($definition as $name => $value) {
            if (! is_string($name)) {
                return null;
            }
            $document[$name] = $value;
        }

        return $document;
    }

    /**
     * @param list<mixed> $rawSteps
     * @param list<FlowDiagnostic> $diagnostics
     * @return array<string, int>
     */
    private function stepIds(array $rawSteps, array &$diagnostics): array
    {
        $idIndexes = [];
        foreach ($rawSteps as $index => $rawStep) {
            if (! is_array($rawStep)) {
                $diagnostics[] = $this->error('step_not_object', 'Each flow step must be a JSON object.', '/steps/'.$index);
                continue;
            }

            $id = $rawStep['id'] ?? null;
            if (! is_string($id) || $id === '') {
                $diagnostics[] = $this->error('missing_step_id', 'A flow step requires a non-empty string id.', '/steps/'.$index.'/id');
                continue;
            }

            if (isset($idIndexes[$id])) {
                $diagnostics[] = $this->error(
                    'duplicate_step_id',
                    "Flow step id '{$id}' is used more than once.",
                    '/steps/'.$index.'/id',
                    $id,
                );
                continue;
            }

            $idIndexes[$id] = $index;
        }

        return $idIndexes;
    }

    /**
     * @param list<mixed> $rawSteps
     * @param array<string, int> $idIndexes
     * @param list<FlowDiagnostic> $diagnostics
     * @return array<string, list<string>> Cleaned dependencies, keyed by step id.
     */
    private function dependencies(array $rawSteps, array $idIndexes, array &$diagnostics): array
    {
        $dependencies = [];
        $seen = [];
        foreach ($rawSteps as $index => $rawStep) {
            if (! is_array($rawStep)) {
                continue;
            }
            $id = $rawStep['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            // A duplicate id was already reported; letting it overwrite the
            // first occurrence would erase that step's real dependencies.
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $declared = $rawStep['depends_on'] ?? null;
            if ($declared !== null && ! is_array($declared)) {
                $diagnostics[] = $this->error(
                    'invalid_dependencies',
                    "Step '{$id}' depends_on must be a list of step ids.",
                    '/steps/'.$index.'/depends_on',
                    $id,
                );
                $declared = [];
            }

            $cleaned = [];
            foreach (is_array($declared) ? array_values($declared) : [] as $position => $dependency) {
                $path = '/steps/'.$index.'/depends_on/'.$position;
                if (! is_string($dependency) || $dependency === '') {
                    $diagnostics[] = $this->error('invalid_dependency', "Step '{$id}' has a non-string dependency.", $path, $id);
                    continue;
                }
                if ($dependency === $id) {
                    // Self-dependencies are terminal here; keeping them out
                    // of the graph avoids a duplicate cycle finding.
                    $diagnostics[] = $this->error('self_dependency', "Step '{$id}' depends on itself.", $path, $id);
                    continue;
                }
                if (! isset($idIndexes[$dependency])) {
                    $diagnostics[] = $this->error(
                        'unknown_dependency',
                        "Step '{$id}' depends on unknown step '{$dependency}'.",
                        $path,
                        $id,
                    );
                    continue;
                }
                $cleaned[] = $dependency;
            }

            $dependencies[$id] = $cleaned;
        }

        return $dependencies;
    }

    /**
     * @param array<string, list<string>> $dependencies
     * @param array<string, int> $idIndexes
     * @return list<FlowDiagnostic>
     */
    private function cycleDiagnostics(array $dependencies, array $idIndexes): array
    {
        $diagnostics = [];
        $colors = [];
        $path = [];
        $reported = [];

        $visit = function (string $id) use (&$visit, &$colors, &$path, &$reported, &$diagnostics, $dependencies, $idIndexes): void {
            $colors[$id] = 1;
            $path[] = $id;

            foreach ($dependencies[$id] ?? [] as $next) {
                $color = $colors[$next] ?? 0;
                if ($color === 1) {
                    $position = array_search($next, $path, true);
                    $cycle = $position === false ? [$next] : array_slice($path, $position);
                    $signature = $cycle;
                    sort($signature);
                    $signature = implode('|', $signature);
                    if (isset($reported[$signature])) {
                        continue;
                    }
                    $reported[$signature] = true;
                    $first = $cycle[0];
                    $diagnostics[] = $this->error(
                        'dependency_cycle',
                        'Flow steps form a dependency cycle: '.implode(' -> ', [...$cycle, $first]).'.',
                        '/steps/'.($idIndexes[$first] ?? 0),
                        $first,
                        ['cycle' => $cycle],
                    );
                    continue;
                }

                if ($color === 0) {
                    $visit($next);
                }
            }

            array_pop($path);
            $colors[$id] = 2;
        };

        foreach (array_keys($dependencies) as $id) {
            if (($colors[$id] ?? 0) === 0) {
                $visit($id);
            }
        }

        return $diagnostics;
    }

    /**
     * @param list<mixed> $rawSteps
     * @param array<string, int> $idIndexes
     * @param array<string, list<string>> $dependencies
     * @param list<FlowDiagnostic> $diagnostics
     */
    private function bindings(array $rawSteps, array $idIndexes, array $dependencies, array &$diagnostics): void
    {
        foreach ($rawSteps as $index => $rawStep) {
            if (! is_array($rawStep)) {
                continue;
            }
            $id = $rawStep['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }

            $declared = $rawStep['input'] ?? null;
            if ($declared !== null && ! is_array($declared)) {
                $diagnostics[] = $this->error(
                    'invalid_input_bindings',
                    "Step '{$id}' input must be an object of named bindings.",
                    '/steps/'.$index.'/input',
                    $id,
                );
                continue;
            }

            foreach (is_array($declared) ? $declared : [] as $name => $binding) {
                if (! is_string($name) || $name === '') {
                    $diagnostics[] = $this->error(
                        'invalid_binding_name',
                        "Step '{$id}' input binding names must be non-empty strings.",
                        '/steps/'.$index.'/input',
                        $id,
                    );
                    continue;
                }

                $bindingPath = '/steps/'.$index.'/input/'.JsonPointer::escape($name);
                if (! is_array($binding)) {
                    $diagnostics[] = $this->error('invalid_binding', "Step '{$id}' input '{$name}' must be a binding object.", $bindingPath, $id);
                    continue;
                }

                $source = $binding['source'] ?? null;
                if ($source !== FlowInputSource::CONTEXT->value && $source !== FlowInputSource::OUTPUT->value) {
                    $diagnostics[] = $this->error(
                        'invalid_binding_source',
                        "Step '{$id}' input '{$name}' source must be 'context' or 'output'.",
                        $bindingPath.'/source',
                        $id,
                    );
                    continue;
                }

                $pointer = $binding['pointer'] ?? '';
                if (! is_string($pointer) || ($pointer !== '' && ! str_starts_with($pointer, '/'))) {
                    $diagnostics[] = $this->error(
                        'invalid_pointer',
                        "Step '{$id}' input '{$name}' pointer must be empty or start with '/'.",
                        $bindingPath.'/pointer',
                        $id,
                    );
                }

                $required = $binding['required'] ?? true;
                if (! is_bool($required)) {
                    $diagnostics[] = $this->warning(
                        'invalid_binding_required',
                        "Step '{$id}' input '{$name}' required must be a boolean; it is read as one.",
                        $bindingPath.'/required',
                        $id,
                    );
                }

                $sourceStep = $binding['step'] ?? null;
                if ($source === FlowInputSource::OUTPUT->value) {
                    if (! is_string($sourceStep) || $sourceStep === '') {
                        $diagnostics[] = $this->error(
                            'binding_source_missing_step',
                            "Step '{$id}' input '{$name}' reads an output without naming its step.",
                            $bindingPath.'/step',
                            $id,
                        );
                        continue;
                    }
                    if (! isset($idIndexes[$sourceStep])) {
                        $diagnostics[] = $this->error(
                            'binding_source_step_unknown',
                            "Step '{$id}' input '{$name}' reads output of unknown step '{$sourceStep}'.",
                            $bindingPath.'/step',
                            $id,
                        );
                        continue;
                    }
                    if (! in_array($sourceStep, $dependencies[$id] ?? [], true)) {
                        $diagnostics[] = $this->error(
                            'binding_source_step_not_dependency',
                            "Step '{$id}' input '{$name}' reads output of step '{$sourceStep}', which is not a declared dependency.",
                            $bindingPath.'/step',
                            $id,
                        );
                    }
                    continue;
                }

                if ($sourceStep !== null) {
                    $diagnostics[] = $this->error(
                        'binding_context_has_step',
                        "Step '{$id}' input '{$name}' reads the context and cannot name a step.",
                        $bindingPath.'/step',
                        $id,
                    );
                }
            }
        }
    }

    /**
     * Match each step to the executor that would run it and collect the
     * operation descriptors those executors publish.
     *
     * @param list<mixed> $rawSteps
     * @param list<mixed> $executors
     * @param list<FlowDiagnostic> $diagnostics
     * @return array<string, FlowOperation> Keyed by step id.
     */
    private function operations(array $rawSteps, array $executors, array &$diagnostics): array
    {
        $operations = [];

        foreach ($rawSteps as $index => $rawStep) {
            if (! is_array($rawStep)) {
                continue;
            }
            $id = $rawStep['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }

            $step = null;
            try {
                $step = FlowStepDefinition::fromArray($rawStep);
            } catch (InvalidArgumentException $exception) {
                if (! $this->hasErrorUnder($diagnostics, '/steps/'.$index)) {
                    $diagnostics[] = $this->error('invalid_step', $exception->getMessage(), '/steps/'.$index, $id);
                }
                continue;
            }

            $executor = null;
            foreach ($executors as $candidate) {
                if ($candidate instanceof FlowStepExecutor && $candidate->supports($step)) {
                    $executor = $candidate;
                    break;
                }
            }

            if ($executor === null) {
                if ($executors !== []) {
                    $diagnostics[] = $this->error(
                        'unsupported_step',
                        "No executor bound on this host supports step '{$id}'.",
                        '/steps/'.$index,
                        $id,
                    );
                }
                continue;
            }

            if ($executor instanceof FlowOperationProvider) {
                $operation = $executor->operation($step);
                if ($operation !== null) {
                    $operations[$id] = $operation;
                }
            }
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, FlowOperation> $operations
     * @param array<string, int> $idIndexes
     * @param list<FlowDiagnostic> $diagnostics
     */
    private function capabilities(array $document, array $operations, array $idIndexes, array &$diagnostics): void
    {
        $checking = $this->grantedCapabilities !== null;
        $granted = $this->grantedCapabilities ?? [];
        $found = false;

        $metadata = $document['metadata'] ?? null;
        $declared = is_array($metadata) ? ($metadata['required_capabilities'] ?? null) : null;
        if ($declared !== null && ! is_array($declared)) {
            $diagnostics[] = $this->warning(
                'invalid_capability',
                'Definition metadata required_capabilities must be a list of capability names.',
                '/metadata/required_capabilities',
            );
            $declared = null;
        }
        foreach (is_array($declared) ? array_values($declared) : [] as $position => $capability) {
            $path = '/metadata/required_capabilities/'.$position;
            if (! is_string($capability) || $capability === '') {
                $diagnostics[] = $this->warning('invalid_capability', 'Capability names must be non-empty strings.', $path);
                continue;
            }
            $found = true;
            if ($checking && ! in_array($capability, $granted, true)) {
                $diagnostics[] = $this->error(
                    'missing_capability',
                    "The definition requires the '{$capability}' capability, which this host does not grant.",
                    $path,
                    null,
                    ['capability' => $capability],
                );
            }
        }

        foreach ($operations as $id => $operation) {
            foreach ($operation->capabilities as $capability) {
                $found = true;
                if ($checking && ! in_array($capability, $granted, true)) {
                    $diagnostics[] = $this->error(
                        'missing_capability',
                        "Operation '{$operation->key}' requires the '{$capability}' capability, which this host does not grant.",
                        '/steps/'.($idIndexes[$id] ?? 0),
                        $id,
                        ['capability' => $capability, 'operation' => $operation->key],
                    );
                }
            }
        }

        if ($found && ! $checking) {
            $diagnostics[] = $this->warning(
                'capabilities_unchecked',
                'Capability requirements were not checked: the host supplied no granted capabilities.',
            );
        }
    }

    /**
     * Optional schema checks: required input properties must be bound, and
     * output bindings must name a property the source operation may produce.
     *
     * @param list<mixed> $rawSteps
     * @param array<string, FlowOperation> $operations
     * @param array<string, int> $idIndexes
     * @param list<FlowDiagnostic> $diagnostics
     */
    private function schemas(array $rawSteps, array $operations, array $idIndexes, array &$diagnostics): void
    {
        $steps = [];
        foreach ($rawSteps as $rawStep) {
            if (! is_array($rawStep)) {
                continue;
            }
            $id = $rawStep['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $steps[$id] = $rawStep;
            }
        }

        foreach ($steps as $id => $rawStep) {
            $operation = $operations[$id] ?? null;
            if ($operation === null) {
                continue;
            }
            $path = '/steps/'.($idIndexes[$id] ?? 0);

            $inputSchema = $operation->inputSchema;
            $required = is_array($inputSchema) ? ($inputSchema['required'] ?? null) : null;
            if ($required !== null && ! is_array($required)) {
                $diagnostics[] = $this->warning(
                    'invalid_schema',
                    "Operation '{$operation->key}' input schema 'required' must be a list of property names.",
                    $path,
                    $id,
                    ['operation' => $operation->key],
                );
                $required = null;
            }

            $bound = is_array($rawStep['input'] ?? null) ? $rawStep['input'] : [];
            foreach (is_array($required) ? array_values($required) : [] as $property) {
                if (! is_string($property) || $property === '') {
                    continue;
                }
                if (! array_key_exists($property, $bound)) {
                    $diagnostics[] = $this->error(
                        'missing_required_input',
                        "Operation '{$operation->key}' requires input '{$property}', which step '{$id}' does not bind.",
                        $path.'/input',
                        $id,
                        ['input' => $property, 'operation' => $operation->key],
                    );
                }
            }
        }

        // Output pointers are checked against the *source* step's operation:
        // that is where the value must exist at runtime.
        foreach ($steps as $id => $rawStep) {
            $bound = is_array($rawStep['input'] ?? null) ? $rawStep['input'] : [];
            foreach ($bound as $name => $binding) {
                if (! is_string($name)
                    || ! is_array($binding)
                    || ($binding['source'] ?? null) !== FlowInputSource::OUTPUT->value) {
                    continue;
                }
                $sourceStep = $binding['step'] ?? null;
                if (! is_string($sourceStep) || $sourceStep === '') {
                    continue;
                }
                $sourceOperation = $operations[$sourceStep] ?? null;
                if ($sourceOperation === null) {
                    continue;
                }
                $outputSchema = $sourceOperation->outputSchema;
                $properties = is_array($outputSchema) ? ($outputSchema['properties'] ?? null) : null;
                if (! is_array($properties)) {
                    continue;
                }

                $segment = $this->firstPointerSegment($binding['pointer'] ?? '');
                if ($segment === null || array_key_exists($segment, $properties)) {
                    continue;
                }

                $diagnostics[] = $this->warning(
                    'unknown_output_property',
                    "Binding '{$name}' reads '{$segment}' from step '{$sourceStep}', which operation '{$sourceOperation->key}' does not declare.",
                    '/steps/'.($idIndexes[$id] ?? 0).'/input/'.JsonPointer::escape($name).'/pointer',
                    $id,
                    [
                        'input' => $name,
                        'property' => $segment,
                        'source_step' => $sourceStep,
                        'operation' => $sourceOperation->key,
                    ],
                );
            }
        }
    }

    private function firstPointerSegment(mixed $pointer): ?string
    {
        if (! is_string($pointer) || $pointer === '' || ! str_starts_with($pointer, '/')) {
            return null;
        }

        $raw = explode('/', substr($pointer, 1))[0];

        return str_replace(['~1', '~0'], ['/', '~'], $raw);
    }

    /** @param array<string, mixed> $details */
    private function error(
        string $code,
        string $message,
        string $path = '',
        ?string $stepId = null,
        array $details = [],
    ): FlowDiagnostic {
        return new FlowDiagnostic($code, $message, FlowDiagnosticSeverity::ERROR, $path, $stepId, $details);
    }

    /** @param array<string, mixed> $details */
    private function warning(
        string $code,
        string $message,
        string $path = '',
        ?string $stepId = null,
        array $details = [],
    ): FlowDiagnostic {
        return new FlowDiagnostic($code, $message, FlowDiagnosticSeverity::WARNING, $path, $stepId, $details);
    }

    /** @param list<FlowDiagnostic> $diagnostics */
    private function hasErrorUnder(array $diagnostics, string $path): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity !== FlowDiagnosticSeverity::ERROR) {
                continue;
            }
            if ($diagnostic->path === $path || str_starts_with($diagnostic->path, $path.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<FlowDiagnostic> $diagnostics */
    private function hasError(array $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === FlowDiagnosticSeverity::ERROR) {
                return true;
            }
        }

        return false;
    }
}
