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

        foreach ($tasks as $index => $task) {
            $step = $this->translateTask($task, $index, $previousTaskId, $document);
            if ($step !== null) {
                $steps[] = $step;
                $previousTaskId = $step->id;
            }
        }

        return $steps;
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

        if (isset($task['set'])) {
            $this->addUnsupported(
                feature: 'set',
                path: "/do/{$index}",
                reason: 'Variable assignment (set) not supported in initial subset'
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
        ];

        // Conditional routing is in the ADR 0006 subset: preserve the full
        // switch structure (named cases with when/then) as step metadata so a
        // runtime can evaluate and honor it.
        if (isset($task['switch'])) {
            $metadata['ows_type'] = 'switch';
            $metadata['ows_switch'] = $task['switch'];
        }

        // Composite tasks carry a nested task list. The composite itself
        // translates as one step, but nested constructs must not be silently
        // accepted: flag anything the adapter cannot execute.
        if (isset($task['do']) && is_array($task['do'])) {
            $this->flagUnsupportedInNestedTasks($task['do'], "/do/{$index}/{$taskId}/do");
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

    /**
     * Flag unsupported constructs inside nested task lists (composite `do`
     * bodies) so they are reported with their nested document path instead of
     * being silently accepted.
     *
     * @param list<mixed> $tasks
     */
    private function flagUnsupportedInNestedTasks(array $tasks, string $basePath): void
    {
        foreach (OwsDocument::normalizeTaskItems($tasks) as $i => $task) {
            $path = "{$basePath}/{$i}";
            $name = is_string($task['name'] ?? null) ? $task['name'] : (string) $i;

            if (isset($task['for'])) {
                $this->addUnsupported('for', $path, 'Iteration (for) not supported in initial subset');
            }
            if (isset($task['fork'])) {
                $this->addUnsupported('fork', $path, 'Concurrency (fork) not supported in initial subset');
            }
            if (isset($task['wait']) || isset($task['listen'])) {
                $this->addUnsupported('wait|listen', $path, 'Duration waits and event listening not supported in initial subset');
            }
            if (isset($task['try'])) {
                $this->addUnsupported('try', $path, 'Structured error handling (try/catch) not supported in initial subset');
            }
            if (isset($task['set'])) {
                $this->addUnsupported('set', $path, 'Variable assignment (set) not supported in initial subset');
            }
            if (isset($task['emit'])) {
                $this->addUnsupported('emit', $path, 'Event emission (emit) not supported in initial subset');
            }
            if (isset($task['raise'])) {
                $this->addUnsupported('raise', $path, 'Error raising (raise) not supported in initial subset');
            }

            // Recurse into deeper composite bodies
            if (isset($task['do']) && is_array($task['do'])) {
                $this->flagUnsupportedInNestedTasks($task['do'], "{$path}/{$name}/do");
            }
        }
    }
}