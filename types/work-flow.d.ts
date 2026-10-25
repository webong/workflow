export type FlowStatus = 'pending' | 'running' | 'attention' | 'completed' | 'blocked' | 'cancelled';
export type FlowStepStatus = 'pending' | 'running' | 'completed' | 'failed' | 'skipped';
export type FlowEventType =
    | 'started'
    | 'step_started'
    | 'step_completed'
    | 'step_failed'
    | 'step_skipped'
    | 'step_deferred'
    | 'completed'
    | 'attention_required'
    | 'blocked'
    | 'reset';

/**
 * A declarative input binding. Paths are RFC 6901 JSON Pointers; the empty
 * pointer selects the whole source document.
 */
export interface FlowInputBinding {
    /** Where the value comes from: the run context or a dependency output. */
    source: 'context' | 'output';
    /** JSON Pointer into the source document. */
    pointer: string;
    /** Source step id for `output` bindings; always declared in depends_on. */
    step: string | null;
    /** Unresolved required bindings fail closed before the executor runs. */
    required: boolean;
}

export interface FlowStepDefinition {
    id: string;
    label: string;
    critical: boolean;
    retriable: boolean;
    depends_on: string[];
    metadata: Record<string, unknown>;
    retry_policy: FlowRetryPolicy | null;
    /**
     * Declarative input bindings keyed by input name. Absent when the step
     * declares none (documents written before bindings exist).
     */
    input?: Record<string, FlowInputBinding>;
}

export interface FlowDefinition {
    key: string;
    version: number;
    steps: FlowStepDefinition[];
    metadata: Record<string, unknown>;
}

export interface StepState {
    status: FlowStepStatus;
    message: string | null;
    error: string | null;
    updated_at: string | null;
    retriable: boolean | null;
    attempts: number;
    next_retry_at: number | null;
    metadata: Record<string, unknown>;
    /**
     * First-class structured output of the step. The property is omitted
     * when the step has no output; when present it carries any JSON value,
     * including an explicit null.
     */
    output?: unknown;
}

export interface FlowState {
    status: FlowStatus;
    version: number;
    steps: Record<string, StepState>;
    current_step: string | null;
    failed_steps: string[];
    can_retry_step: string | null;
    status_message: string | null;
    metadata: Record<string, unknown>;
    run: FlowRun | null;
}

export interface FlowRun {
    id: string;
    definition: FlowDefinition;
}

export interface FlowAction {
    key: string;
    label: string;
    payload: Record<string, unknown>;
    enabled: boolean;
    metadata: Record<string, unknown>;
}

export interface FlowPresentation {
    severity: string;
    message: string;
    actions: FlowAction[];
    metadata: Record<string, unknown>;
}

export interface FlowActionContext {
    actor: unknown;
    resource: unknown;
    attributes: Record<string, unknown>;
}

export interface FlowRetryPolicy {
    enabled: boolean;
    max_attempts: number;
    backoff_seconds: number;
    idempotent: boolean;
}

export interface FlowEvent {
    type: FlowEventType;
    flow_key: string;
    step_id: string | null;
    payload: Record<string, unknown>;
    occurred_at: string;
}
