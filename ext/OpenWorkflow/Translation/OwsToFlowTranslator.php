<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Translation;

use Webong\WorkFlow\OpenWorkflow\Contracts\UnsupportedFeature;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionParser;
use Webong\WorkFlow\OpenWorkflow\ValueObjects\OwsDocument;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowRetryPolicy;

/**
 * Translates a validated OWS document into a WorkFlow FlowDefinition.
 * Reports unsupported constructs explicitly.
 */
final class OwsToFlowTranslator
{
    /** @var list<string> */
    private array $supportedFeatures = [
        'sequential-task',
        'switch',
        'case',
        'default',
        'then:exit',
        'then:end',
        'then:task',
        'call',
        'run:http',
        'run:script',
        'set',
        'input-filtering',
        'data-merge',
        'expression:property-access',
        'expression:array-projection',
        'expression:pipe',
        'expression:comparison',
        'expression:boolean',
        'expression:object-construction',
        'expression:literal',
    ];

    /** @var list<UnsupportedFeature> */
    private array $unsupportedFeatures = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly ExpressionEvaluator $expressionEvaluator,
    ) {}

    /**
     * Translate an OWS document to a FlowDefinition.
     *
     * @return array{flowDefinition: ?FlowDefinition, supported: list<string>, unsupported: list<UnsupportedFeature>, warnings: list<string>}
     */
    public function translate(OwsDocument $document): array
    {
        $this->unsupportedFeatures = [];
        $this->warnings = [];

        $compositeKey = $document->compositeKey();
        if ($compositeKey === '') {
            $this->warnings[] = 'Document missing namespace or name; generated key will be incomplete';
        }

        $steps = $this->translateTasks($document->do(), $document);
        $steps = $this->annotateChain($steps);
        $version = $this->parseVersion($document->semver());

        $flowDefinition = new FlowDefinition(
            key: $compositeKey !== '' ? $compositeKey : 'ows-import/' . uniqid(),
            steps: $steps,
            version: $version,
            metadata: array_merge(
                $document->metadata(),
                [
                    'ows_namespace' => $document->namespace(),
                    'ows_name' => $document->name(),
                    'ows_version' => $document->semver(),
                    'ows_dsl_version' => $document->version(),
                ],
            ),
        );

        return [
            'flowDefinition' => $flowDefinition,
            'supported' => $this->supportedFeatures,
            'unsupported' => $this->unsupportedFeatures,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<FlowStepDefinition>
     */
    private function translateTasks(array $tasks, OwsDocument $document): array
    {
        $steps = [];
        $previousTaskId = null;

        foreach ($this->orderTasks($tasks) as $index => $task) {
            // Composite tasks (nested `do` without a primary task type) flatten
            // into top-level steps so nested constructs go through the same
            // translation, flagging, and execution paths as top-level tasks.
            if ($this->isComposite($task)) {
                $nested = OwsDocument::normalizeTaskItems($task['do']);
                $flattened = $this->translateTasks($nested, $document);
                foreach ($flattened as $nestedStep) {
                    $steps[] = $nestedStep;
                    $previousTaskId = $nestedStep->id;
                }
                continue;
            }

            $step = $this->translateTask($task, $index, $previousTaskId, $document);
            if ($step !== null) {
                $steps[] = $step;
                $previousTaskId = $step->id;
            }
        }

        return $steps;
    }

    /**
     * Annotate the flat chain with execution positions and give switch steps
     * the full chain map plus normalized cases, so runtime routing can decide
     * which steps skip without access to the whole definition.
     *
     * @param list<FlowStepDefinition> $steps
     * @return list<FlowStepDefinition>
     */
    private function annotateChain(array $steps): array
    {
        $positions = [];
        $previousById = [];
        foreach ($steps as $index => $step) {
            $positions[$step->id] = $index;
            $previousById[$step->id] = $step->dependsOn === [] ? null : $step->dependsOn[count($step->dependsOn) - 1];
        }

        $annotated = [];
        foreach ($steps as $index => $step) {
            $metadata = [
                ...$step->metadata,
                'ows_chain_position' => $index,
                'ows_previous_by_id' => $previousById,
            ];

            if (($metadata['ows_type'] ?? null) === 'switch') {
                $metadata['ows_chain_positions'] = $positions;
                $metadata['ows_switch_cases'] = $this->normalizeSwitchCases(
                    is_array($metadata['ows_switch'] ?? null) ? $metadata['ows_switch'] : [],
                    $steps,
                );
            }

            $annotated[] = $this->withMetadata($step, $metadata);
        }

        return $annotated;
    }

    /**
     * Normalize an OWS `switch` value into ordered cases with a per-case
     * `end_after` flag (the target's own `then` terminates the chain).
     *
     * OWS accepts two shapes: a map of case name => case definition, or a
     * sequence of single-entry maps (`- caseName: {...}`) as used in the CTK.
     *
     * @param array<string|int, mixed> $switch
     * @param list<FlowStepDefinition> $steps
     * @return list<array{name: string, when: ?string, then: ?string, end_after: bool}>
     */
    private function normalizeSwitchCases(array $switch, array $steps): array
    {
        $thenById = [];
        foreach ($steps as $step) {
            $thenById[$step->id] = $step->metadata['ows_then'] ?? null;
        }

        $cases = [];
        foreach ($switch as $key => $value) {
            // Sequence form: each item is a single-entry map keyed by case name.
            if (is_int($key) && is_array($value) && count($value) === 1) {
                $caseName = (string) array_key_first($value);
                $case = $value[$caseName];
            } else {
                $caseName = (string) $key;
                $case = $value;
            }

            if (! is_array($case)) {
                continue;
            }
            $when = is_string($case['when'] ?? null) ? $case['when'] : null;
            $then = is_string($case['then'] ?? null) ? $case['then'] : null;
            $cases[] = [
                'name' => $caseName,
                'when' => $when,
                'then' => $then,
                // A case without `when` is the explicit default: it matches
                // anything the earlier cases did not.
                'end_after' => $then !== null && in_array($thenById[$then] ?? null, ['end', 'exit'], true),
            ];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function withMetadata(FlowStepDefinition $step, array $metadata): FlowStepDefinition
    {
        return new FlowStepDefinition(
            id: $step->id,
            label: $step->label,
            critical: $step->critical,
            retriable: $step->retriable,
            dependsOn: $step->dependsOn,
            metadata: $metadata,
            retryPolicy: $step->retryPolicy,
        );
    }

    /**
     * Order tasks for the execution chain per OWS flow directives: follow
     * each task's `then` target; `then: end`/`exit` terminates the chain;
     * tasks without `then` fall through in declaration order. Tasks the
     * chain never reaches (dead code after `then: end`, or switch routing
     * targets) are appended in declaration order so they still translate.
     *
     * @param list<array<string, mixed>> $tasks
     * @return list<array<string, mixed>>
     */
    private function orderTasks(array $tasks): array
    {
        // Tasks arrive already normalized (see OwsDocument::normalizeTaskItems
        // via do() and composite flattening) — re-normalizing here would
        // misinterpret each task's own keys as new tasks.
        $normalized = $tasks;
        if (count($normalized) <= 1) {
            return $normalized;
        }

        $byName = [];
        foreach ($normalized as $task) {
            if (is_string($task['name'] ?? null) && $task['name'] !== '') {
                $byName[$task['name']] = $task;
            }
        }

        $ordered = [];
        $visited = [];
        $current = $normalized[0] ?? null;

        while ($current !== null) {
            $name = is_string($current['name'] ?? null) ? $current['name'] : '';
            if ($name === '' || isset($visited[$name])) {
                break; // unnamed or cyclic `then` — stop following the chain
            }
            $visited[$name] = true;
            $ordered[] = $current;

            $then = $current['then'] ?? null;
            if ($then === 'end' || $then === 'exit') {
                break;
            }
            if (is_string($then) && isset($byName[$then])) {
                $current = $byName[$then];
                continue;
            }

            $position = array_search($current, $normalized, true);
            $current = $position !== false ? ($normalized[$position + 1] ?? null) : null;
        }

        foreach ($normalized as $task) {
            $name = is_string($task['name'] ?? null) ? $task['name'] : '';
            if ($name !== '' && ! isset($visited[$name])) {
                $visited[$name] = true;
                $ordered[] = $task;
            }
        }

        return $ordered;
    }

    /**
     * A composite task carries a nested `do` list and no primary task type.
     *
     * @param array<string, mixed> $task
     */
    private function isComposite(array $task): bool
    {
        if (! isset($task['do']) || ! is_array($task['do'])) {
            return false;
        }
        $primaryTypes = ['call', 'run', 'switch', 'set', 'for', 'fork', 'wait', 'listen', 'try', 'emit', 'raise'];
        foreach ($primaryTypes as $type) {
            if (isset($task[$type])) {
                return false;
            }
        }
        return true;
    }

    private function translateTask(array $task, int $index, ?string $previousTaskId, OwsDocument $document): ?FlowStepDefinition
    {
        // Handle different task types
        if (isset($task['call'])) {
            return $this->translateCallTask($task, $index, $previousTaskId);
        }

        if (isset($task['run'])) {
            return $this->translateRunTask($task, $index, $previousTaskId);
        }

        if (isset($task['switch'])) {
            // Conditional routing is in the ADR 0006 initial subset. The switch
            // cases are preserved as step metadata; evaluating `when` at
            // runtime and honoring `then` routing is a runtime concern.
            return $this->translateGenericTask($task, $index, $previousTaskId);
        }

        if (isset($task['set'])) {
            return $this->translateSetTask($task, $index, $previousTaskId);
        }

        if (isset($task['for'])) {
            $this->addUnsupported(
                feature: 'for',
                path: "/do/{$index}",
                reason: 'Iteration (for) not supported in initial subset'
            );
            return null;
        }

        if (isset($task['fork'])) {
            $this->addUnsupported(
                feature: 'fork',
                path: "/do/{$index}",
                reason: 'Concurrency (fork) not supported in initial subset'
            );
            return null;
        }

        if (isset($task['wait']) || isset($task['listen'])) {
            $this->addUnsupported(
                feature: 'wait|listen',
                path: "/do/{$index}",
                reason: 'Duration waits and event listening not supported in initial subset'
            );
            return null;
        }

        if (isset($task['try'])) {
            $this->addUnsupported(
                feature: 'try',
                path: "/do/{$index}",
                reason: 'Structured error handling (try/catch) not supported in initial subset'
            );
            return null;
        }

        if (isset($task['emit'])) {
            $this->addUnsupported(
                feature: 'emit',
                path: "/do/{$index}",
                reason: 'Event emission (emit) not supported in initial subset'
            );
            return null;
        }

        if (isset($task['raise'])) {
            $this->addUnsupported(
                feature: 'raise',
                path: "/do/{$index}",
                reason: 'Error raising (raise) not supported in initial subset'
            );
            return null;
        }

        // Generic task with name and action
        return $this->translateGenericTask($task, $index, $previousTaskId);
    }

    private function translateCallTask(array $task, int $index, ?string $previousTaskId): ?FlowStepDefinition
    {
        $call = $task['call'];
        $taskId = (string) ($task['name'] ?? "call_{$index}");

        $dependsOn = $this->computeDependsOn($task, $previousTaskId);

        // Extract input/output transformations
        $inputExpr = $task['with'] ?? $task['input'] ?? null;
        $outputExpr = $task['export'] ?? $task['output'] ?? null;

        $metadata = [
            'ows_type' => 'call',
            'ows_call' => $call,
            'ows_input_expression' => $inputExpr,
            'ows_output_expression' => $outputExpr,
            'ows_original_index' => $index,
            'ows_previous' => $previousTaskId,
        ];

        if (isset($task['then'])) {
            $metadata['ows_then'] = $task['then'];
            if ($task['then'] !== 'exit' && $task['then'] !== 'end') {
                // then points to another task - we record it for runtime
                $this->supportedFeatures[] = 'then:task';
            } else {
                $this->supportedFeatures[] = 'then:' . $task['then'];
            }
        }

        return new FlowStepDefinition(
            id: $taskId,
            label: $task['name'] ?? $call,
            critical: (bool) ($task['critical'] ?? true),
            retriable: (bool) ($task['retriable'] ?? false),
            dependsOn: $dependsOn,
            metadata: $metadata,
            retryPolicy: $this->translateRetryPolicy($task['retry'] ?? null),
        );
    }

    private function translateRunTask(array $task, int $index, ?string $previousTaskId): ?FlowStepDefinition
    {
        $run = $task['run'];
        $taskId = (string) ($task['name'] ?? "run_{$index}");

        $dependsOn = $this->computeDependsOn($task, $previousTaskId);

        $inputExpr = $task['with'] ?? $task['input'] ?? null;
        $outputExpr = $task['export'] ?? $task['output'] ?? null;

        $runType = 'unknown';
        $runTarget = '';

        if (is_string($run)) {
            // Could be a URI or inline script
            if (str_starts_with($run, 'http://') || str_starts_with($run, 'https://')) {
                $runType = 'http';
                $runTarget = $run;
                $this->supportedFeatures[] = 'run:http';
            } else {
                $runType = 'script';
                $runTarget = $run;
                $this->supportedFeatures[] = 'run:script';
            }
        } elseif (is_array($run)) {
            // Structured run specification
            if (isset($run['http'])) {
                $runType = 'http';
                $runTarget = $run['http'];
                $this->supportedFeatures[] = 'run:http';
            } elseif (isset($run['script'])) {
                $runType = 'script';
                $runTarget = $run['script'];
                $this->supportedFeatures[] = 'run:script';
            } elseif (isset($run['container'])) {
                $this->addUnsupported(
                    feature: 'run:container',
                    path: "/do/{$index}/run/container",
                    reason: 'Container execution not supported in initial subset'
                );
                return null;
            } elseif (isset($run['workflow'])) {
                $this->addUnsupported(
                    feature: 'run:workflow',
                    path: "/do/{$index}/run/workflow",
                    reason: 'Sub-workflow execution not supported in initial subset'
                );
                return null;
            }
        }

        $metadata = [
            'ows_type' => 'run',
            'ows_run_type' => $runType,
            'ows_run_target' => $runTarget,
            'ows_input_expression' => $inputExpr,
            'ows_output_expression' => $outputExpr,
            'ows_original_index' => $index,
            'ows_previous' => $previousTaskId,
        ];

        if (isset($task['then'])) {
            $metadata['ows_then'] = $task['then'];
        }

        return new FlowStepDefinition(
            id: $taskId,
            label: $task['name'] ?? $runTarget,
            critical: (bool) ($task['critical'] ?? true),
            retriable: (bool) ($task['retriable'] ?? false),
            dependsOn: $dependsOn,
            metadata: $metadata,
            retryPolicy: $this->translateRetryPolicy($task['retry'] ?? null),
        );
    }

    private function translateGenericTask(array $task, int $index, ?string $previousTaskId): ?FlowStepDefinition
    {
        $taskId = (string) ($task['name'] ?? "task_{$index}");
        $action = $task['action'] ?? $task['do'] ?? 'unknown';

        $dependsOn = $this->computeDependsOn($task, $previousTaskId);

        $metadata = [
            'ows_type' => 'generic',
            'ows_action' => $action,
            'ows_original_index' => $index,
            'ows_previous' => $previousTaskId,
        ];

        // Conditional routing is in the ADR 0006 subset: preserve the full
        // switch structure (named cases with when/then) as step metadata so a
        // runtime can evaluate and honor it.
        if (isset($task['switch'])) {
            $metadata['ows_type'] = 'switch';
            $metadata['ows_switch'] = $task['switch'];
        }

        if (isset($task['then'])) {
            $metadata['ows_then'] = $task['then'];
        }

        return new FlowStepDefinition(
            id: $taskId,
            label: $task['name'] ?? $action,
            critical: (bool) ($task['critical'] ?? true),
            retriable: (bool) ($task['retriable'] ?? false),
            dependsOn: $dependsOn,
            metadata: $metadata,
            retryPolicy: $this->translateRetryPolicy($task['retry'] ?? null),
        );
    }

    /**
     * Set tasks evaluate each key's jq expression against the workflow data
     * and merge the results into it — data transformation, not a side effect.
     * `input.from` optionally filters the task's data scope first.
     */
    private function translateSetTask(array $task, int $index, ?string $previousTaskId): FlowStepDefinition
    {
        $taskId = (string) ($task['name'] ?? "set_{$index}");
        $dependsOn = $this->computeDependsOn($task, $previousTaskId);

        $inputExpression = isset($task['input']['from']) && is_string($task['input']['from'])
            ? $task['input']['from']
            : null;

        $metadata = [
            'ows_type' => 'set',
            'ows_set' => is_array($task['set']) ? $task['set'] : [],
            'ows_input_expression' => $inputExpression,
            'ows_original_index' => $index,
            'ows_previous' => $previousTaskId,
        ];

        if (isset($task['then'])) {
            $metadata['ows_then'] = $task['then'];
        }

        return new FlowStepDefinition(
            id: $taskId,
            label: $task['name'] ?? $taskId,
            critical: (bool) ($task['critical'] ?? true),
            retriable: (bool) ($task['retriable'] ?? false),
            dependsOn: $dependsOn,
            metadata: $metadata,
            retryPolicy: $this->translateRetryPolicy($task['retry'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private function computeDependsOn(array $task, ?string $previousTaskId): array
    {
        $dependsOn = [];

        // Explicit dependencies
        if (isset($task['dependsOn']) && is_array($task['dependsOn'])) {
            foreach ($task['dependsOn'] as $dep) {
                if (is_string($dep)) {
                    $dependsOn[] = $dep;
                }
            }
        }

        // Implicit sequential dependency (OWS default: tasks run in order)
        if ($previousTaskId !== null && ! in_array($previousTaskId, $dependsOn, true)) {
            $dependsOn[] = $previousTaskId;
        }

        return $dependsOn;
    }

    private function translateRetryPolicy(?array $retry): ?FlowRetryPolicy
    {
        if ($retry === null || ! is_array($retry)) {
            return null;
        }

        $enabled = (bool) ($retry['enabled'] ?? true);
        $maxAttempts = (int) ($retry['maxAttempts'] ?? $retry['max_attempts'] ?? 3);
        $backoffSeconds = (int) ($retry['backoff'] ?? $retry['backoff_seconds'] ?? 0);
        $idempotent = (bool) ($retry['idempotent'] ?? true);

        return new FlowRetryPolicy(
            enabled: $enabled,
            maxAttempts: max(1, $maxAttempts),
            backoffSeconds: max(0, $backoffSeconds),
            idempotent: $idempotent,
        );
    }

    private function parseVersion(string $semver): int
    {
        // Extract major version from semver for integer version field
        // e.g., "1.2.3" -> 1, "2.0.0-beta" -> 2
        if (preg_match('/^(\d+)/', $semver, $matches)) {
            return max(1, (int) $matches[1]);
        }
        return 1;
    }

    private function addUnsupported(string $feature, string $path, string $reason): void
    {
        $this->unsupportedFeatures[] = new UnsupportedFeature(
            feature: $feature,
            path: $path,
            reason: $reason,
        );
    }
}