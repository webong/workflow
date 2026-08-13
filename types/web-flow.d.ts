export type FlowStatus = 'pending' | 'running' | 'attention' | 'completed' | 'blocked';
export type StepStatus = 'pending' | 'running' | 'completed' | 'failed' | 'skipped';
export type PresentationKind = 'banner' | 'card' | 'inline' | 'toast';
export type FlowEventType =
    | 'started'
    | 'step_started'
    | 'step_completed'
    | 'step_failed'
    | 'step_skipped'
    | 'completed'
    | 'attention_required'
    | 'blocked'
    | 'reset';

export interface StepDefinition {
    id: string;
    label: string;
    critical: boolean;
    retriable: boolean;
    depends_on: string[];
    metadata: Record<string, unknown>;
}

export interface FlowDefinition {
    key: string;
    version: number;
    steps: StepDefinition[];
    metadata: Record<string, unknown>;
}

export interface StepState {
    status: StepStatus;
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
}

export interface FlowAction {
    key: string;
    label: string;
    payload: Record<string, unknown>;
    enabled: boolean;
    metadata: Record<string, unknown>;
}

export interface FlowPresentation {
    kind: PresentationKind;
    severity: string;
    title: string;
    message: string;
    actions: FlowAction[];
    dismissible: boolean;
    metadata: Record<string, unknown>;
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
