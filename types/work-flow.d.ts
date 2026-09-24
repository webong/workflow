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

export interface FlowStepDefinition {
    id: string;
    label: string;
    critical: boolean;
    retriable: boolean;
    depends_on: string[];
    metadata: Record<string, unknown>;
    retry_policy: FlowRetryPolicy | null;
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
