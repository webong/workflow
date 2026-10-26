<?php

declare(strict_types=1);

/**
 * Single source for the generated Node, Python, and Go contracts.
 *
 * The wire format is defined by the PHP `toArray()` methods; this manifest
 * mirrors it for authors working in other languages. It carries types only:
 * validation, execution, and capability rules stay on the server, and no
 * generated client may reimplement them.
 *
 * Field order is intentional: it matches the canonical serialized shape.
 *
 * Type grammar:
 *   string, int, float, bool, unknown        primitives
 *   Name                                     a named enum or type below
 *   Name[]                                   a JSON array
 *   map<Name>                                a JSON object with string keys
 *   Name|null                                a JSON value that may be null
 *   union<a,b>                               two or more alternatives
 *
 * Add `'optional' => true` to a field that is omitted when unset (as opposed
 * to one that is present with a null value).
 */
return [
    'enums' => [
        'FlowStatus' => [
            'description' => 'Overall status of one flow run.',
            'cases' => [
                'pending' => 'PENDING',
                'running' => 'RUNNING',
                'attention' => 'ATTENTION',
                'completed' => 'COMPLETED',
                'blocked' => 'BLOCKED',
                'cancelled' => 'CANCELLED',
            ],
        ],
        'FlowStepStatus' => [
            'description' => 'Status of one step within a run.',
            'cases' => [
                'pending' => 'PENDING',
                'running' => 'RUNNING',
                'completed' => 'COMPLETED',
                'failed' => 'FAILED',
                'skipped' => 'SKIPPED',
            ],
        ],
        'FlowEventType' => [
            'description' => 'Kinds of state-change events a host may persist or stream.',
            'cases' => [
                'started' => 'STARTED',
                'step_started' => 'STEP_STARTED',
                'step_completed' => 'STEP_COMPLETED',
                'step_failed' => 'STEP_FAILED',
                'step_skipped' => 'STEP_SKIPPED',
                'step_deferred' => 'STEP_DEFERRED',
                'input_resolved' => 'INPUT_RESOLVED',
                'input_unresolved' => 'INPUT_UNRESOLVED',
                'completed' => 'COMPLETED',
                'attention_required' => 'ATTENTION_REQUIRED',
                'blocked' => 'BLOCKED',
                'reset' => 'RESET',
            ],
        ],
        'FlowInputSource' => [
            'description' => 'Where a step input binding reads from.',
            'cases' => [
                'context' => 'CONTEXT',
                'output' => 'OUTPUT',
            ],
        ],
        'FlowDiagnosticSeverity' => [
            'description' => 'How a validation finding affects authoring. Only error blocks.',
            'cases' => [
                'error' => 'ERROR',
                'warning' => 'WARNING',
                'info' => 'INFO',
            ],
        ],
    ],
    'types' => [
        'FlowInputBinding' => [
            'description' => 'A declarative step input binding. Pointers are RFC 6901 JSON Pointers; the empty pointer selects the whole source document.',
            'fields' => [
                ['name' => 'source', 'type' => 'FlowInputSource', 'description' => 'The run context or a dependency output.'],
                ['name' => 'pointer', 'type' => 'string', 'description' => 'JSON Pointer into the source document.'],
                ['name' => 'step', 'type' => 'string|null', 'description' => 'Source step id for output bindings; always a declared dependency.'],
                ['name' => 'required', 'type' => 'bool', 'description' => 'Unresolved required bindings fail closed before the executor runs.'],
            ],
        ],
        'FlowStepDefinition' => [
            'description' => 'One step in a flow definition.',
            'fields' => [
                ['name' => 'id', 'type' => 'string', 'description' => 'Unique step id within the definition.'],
                ['name' => 'label', 'type' => 'string', 'description' => 'Human-readable label.'],
                ['name' => 'critical', 'type' => 'bool', 'description' => 'Whether failure blocks the run.'],
                ['name' => 'retriable', 'type' => 'bool', 'description' => 'Whether the host may retry the step.'],
                ['name' => 'depends_on', 'type' => 'string[]', 'description' => 'Step ids that must complete first.'],
                ['name' => 'metadata', 'type' => 'map<unknown>', 'description' => 'Execution bookkeeping; not for domain data.'],
                ['name' => 'retry_policy', 'type' => 'FlowRetryPolicy|null', 'description' => 'Optional retry policy.'],
                ['name' => 'input', 'type' => 'map<FlowInputBinding>', 'optional' => true, 'description' => 'Bindings keyed by input name; omitted when the step declares none.'],
            ],
        ],
        'FlowDefinition' => [
            'description' => 'A versioned, serializable flow definition. Validation is authoritative on the server, never in a client.',
            'fields' => [
                ['name' => 'key', 'type' => 'string', 'description' => 'Stable flow key.'],
                ['name' => 'version', 'type' => 'int', 'description' => 'Positive definition version.'],
                ['name' => 'steps', 'type' => 'FlowStepDefinition[]', 'description' => 'Ordered steps.'],
                ['name' => 'metadata', 'type' => 'map<unknown>', 'description' => 'Optional definition metadata.'],
            ],
        ],
        'FlowOperation' => [
            'description' => 'A stable description of one host-approved operation. Descriptors are authoring data and never execute anything.',
            'fields' => [
                ['name' => 'key', 'type' => 'string', 'description' => 'Stable operation key.'],
                ['name' => 'label', 'type' => 'string', 'description' => 'Human-readable label.'],
                ['name' => 'summary', 'type' => 'string|null', 'description' => 'Optional longer description.'],
                ['name' => 'input_schema', 'type' => 'map<unknown>|null', 'description' => 'Opaque schema document the host interprets.'],
                ['name' => 'output_schema', 'type' => 'map<unknown>|null', 'description' => 'Opaque schema document the host interprets.'],
                ['name' => 'capabilities', 'type' => 'string[]', 'description' => 'Capabilities required to run the operation.'],
                ['name' => 'metadata', 'type' => 'map<unknown>', 'description' => 'Host metadata.'],
            ],
        ],
        'FlowDiagnostic' => [
            'description' => 'One structured validation finding. A diagnostic is data, never an exception.',
            'fields' => [
                ['name' => 'code', 'type' => 'string', 'description' => 'Stable machine-readable code, e.g. duplicate_step_id.'],
                ['name' => 'message', 'type' => 'string', 'description' => 'Human-readable explanation.'],
                ['name' => 'severity', 'type' => 'FlowDiagnosticSeverity', 'description' => 'Only error-severity findings make a report invalid.'],
                ['name' => 'path', 'type' => 'string', 'description' => 'RFC 6901 JSON Pointer into the definition document; empty at the document root.'],
                ['name' => 'step_id', 'type' => 'string|null', 'description' => 'Step the finding concerns, when one applies.'],
                ['name' => 'details', 'type' => 'map<unknown>', 'description' => 'Additional structured context such as the detected cycle.'],
            ],
        ],
        'FlowValidationReport' => [
            'description' => 'The result of validating one flow definition document.',
            'fields' => [
                ['name' => 'valid', 'type' => 'bool', 'description' => 'True when no diagnostic has error severity.'],
                ['name' => 'diagnostics', 'type' => 'FlowDiagnostic[]', 'description' => 'All findings, in the order an author can act on them.'],
            ],
        ],
        'FlowValidateParams' => [
            'description' => 'Parameters for the flow.validate JSON-RPC method.',
            'fields' => [
                ['name' => 'definition', 'type' => 'FlowDefinition', 'description' => 'The document to validate; it need not be registered.'],
            ],
        ],
        'FlowOperationsResult' => [
            'description' => 'Result of the flow.operations JSON-RPC method.',
            'fields' => [
                ['name' => 'operations', 'type' => 'map<FlowOperation>', 'description' => 'Descriptors keyed by step id.'],
            ],
        ],
        'FlowRetryPolicy' => [
            'description' => 'Retry policy a host may honour for a retriable step.',
            'fields' => [
                ['name' => 'enabled', 'type' => 'bool', 'description' => 'Whether retries are allowed.'],
                ['name' => 'max_attempts', 'type' => 'int', 'description' => 'Maximum attempts.'],
                ['name' => 'backoff_seconds', 'type' => 'int', 'description' => 'Delay between attempts.'],
                ['name' => 'idempotent', 'type' => 'bool', 'description' => 'Whether repeating the effect is safe.'],
            ],
        ],
        'StepState' => [
            'description' => 'Serialized state of one step. output and deadline_at are omitted when unset.',
            'fields' => [
                ['name' => 'status', 'type' => 'FlowStepStatus', 'description' => 'Step status.'],
                ['name' => 'message', 'type' => 'string|null', 'description' => 'Human-readable status message.'],
                ['name' => 'error', 'type' => 'string|null', 'description' => 'Failure message.'],
                ['name' => 'updated_at', 'type' => 'string|null', 'description' => 'Host-supplied timestamp.'],
                ['name' => 'retriable', 'type' => 'bool|null', 'description' => 'Whether this attempt may be retried.'],
                ['name' => 'attempts', 'type' => 'int', 'description' => 'Attempt count.'],
                ['name' => 'next_retry_at', 'type' => 'int|null', 'description' => 'Epoch second of the next allowed retry.'],
                ['name' => 'metadata', 'type' => 'map<unknown>', 'description' => 'Execution bookkeeping.'],
                ['name' => 'output', 'type' => 'unknown', 'optional' => true, 'description' => 'First-class structured output; the key is omitted when the step has no output.'],
                ['name' => 'deadline_at', 'type' => 'int', 'optional' => true, 'description' => 'Absolute epoch deadline for a deferred attempt.'],
            ],
        ],
        'FlowRun' => [
            'description' => 'Identity and pinned definition of one run.',
            'fields' => [
                ['name' => 'id', 'type' => 'string', 'description' => 'Run id.'],
                ['name' => 'definition', 'type' => 'FlowDefinition', 'description' => 'The definition snapshot this run uses.'],
            ],
        ],
        'FlowState' => [
            'description' => 'Serialized state of one run.',
            'fields' => [
                ['name' => 'status', 'type' => 'FlowStatus', 'description' => 'Overall status.'],
                ['name' => 'version', 'type' => 'int', 'description' => 'State schema version.'],
                ['name' => 'steps', 'type' => 'map<StepState>', 'description' => 'Step states keyed by step id.'],
                ['name' => 'current_step', 'type' => 'string|null', 'description' => 'Last step that produced state.'],
                ['name' => 'failed_steps', 'type' => 'string[]', 'description' => 'Step ids that failed.'],
                ['name' => 'can_retry_step', 'type' => 'string|null', 'description' => 'Step the host may retry.'],
                ['name' => 'status_message', 'type' => 'string|null', 'description' => 'Human-readable run message.'],
                ['name' => 'metadata', 'type' => 'map<unknown>', 'description' => 'Run metadata.'],
                ['name' => 'run', 'type' => 'FlowRun|null', 'description' => 'Run identity and pinned definition.'],
                ['name' => 'execution_claim', 'type' => 'string|null', 'optional' => true, 'description' => 'Non-null while an execution is in flight or interrupted (RPC responses).'],
            ],
        ],
        'FlowStateResult' => [
            'description' => 'Result of the state-reading and state-changing JSON-RPC methods.',
            'fields' => [
                ['name' => 'state', 'type' => 'FlowState|null', 'description' => 'The current state, or null before the run exists.'],
            ],
        ],
        'FlowAction' => [
            'description' => 'An action a presentation offers to an operator.',
            'fields' => [
                ['name' => 'key', 'type' => 'string', 'description' => 'Action key.'],
                ['name' => 'label', 'type' => 'string', 'description' => 'Human-readable label.'],
                ['name' => 'payload', 'type' => 'map<unknown>', 'description' => 'Opaque data the host interprets.'],
                ['name' => 'enabled', 'type' => 'bool', 'description' => 'Whether the action is currently allowed.'],
                ['name' => 'metadata', 'type' => 'map<unknown>', 'description' => 'Host metadata.'],
            ],
        ],
        'FlowPresentation' => [
            'description' => 'What an operator should see and be able to do next.',
            'fields' => [
                ['name' => 'severity', 'type' => 'string', 'description' => 'Host-defined severity.'],
                ['name' => 'message', 'type' => 'string', 'description' => 'Human-readable message.'],
                ['name' => 'actions', 'type' => 'FlowAction[]', 'description' => 'Offered actions.'],
                ['name' => 'metadata', 'type' => 'map<unknown>', 'description' => 'Host metadata.'],
            ],
        ],
        'FlowEvent' => [
            'description' => 'One persisted or streamed state-change event. Events never contain resolved input values.',
            'fields' => [
                ['name' => 'type', 'type' => 'FlowEventType', 'description' => 'Event kind.'],
                ['name' => 'flow_key', 'type' => 'string', 'description' => 'Flow key.'],
                ['name' => 'step_id', 'type' => 'string|null', 'description' => 'Step the event concerns, when one applies.'],
                ['name' => 'payload', 'type' => 'map<unknown>', 'description' => 'Event data.'],
                ['name' => 'occurred_at', 'type' => 'string', 'description' => 'Host-supplied timestamp.'],
            ],
        ],
    ],
];
