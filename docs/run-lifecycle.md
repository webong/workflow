# Run lifecycle and guarantees

The reference process is channel setup: provider authorization, a webhook
wait, verification, and operator intervention if verification fails. It is
implemented in [the runnable example](../examples/channel-setup.php). These
rules apply equally to an API, CLI, or GUI host.

## Identity and definitions

A `FlowRun` has an ID and a complete definition snapshot. Choose a unique ID
for a new occurrence and reuse it only when retrying the same start request.
Multiple runs for one subject and flow are allowed. A one-active-run rule is
a host policy, enforced in that host's transaction or unique constraint.

The runner, evaluator, and completion handler reject a definition that differs
from the run's snapshot, including edits that reused the same version number.
Map key order is immaterial; step order and values are preserved. A snapshot
contains JSON-compatible values, not mutable service objects.

Publish changed definitions with a new version. Active runs keep their
original definition; the optional Eloquent definition models remain authoring
records. Copy `toFlowDefinition()` into a new `FlowRun` when starting. Editing
those records never changes a saved run. The host owns publication policy and
the availability of compatible executor code for older definitions.

`FlowState::version` is the **state serialization schema version**, independent
of `FlowDefinition::version`. State migrators must preserve run identity and
its definition. In-place definition migration is not provided: finish or
cancel the old run and start a new one after explicit business reconciliation.

## Storage and retention

Wrap a subject-bound `ForgettableFlowStateStore` in `RunScopedFlowStateStore`.
It derives a bounded physical key from the flow key and run ID and validates
the saved identity on reads and writes. All existing store adapters work
without schema changes. Physical keys beginning `__workflow_run__:` are
reserved; application code should use logical flow keys through the wrapper.

Legacy unscoped state remains accessible through its original store and key.
It is not silently attached to a current definition. Keep it on the legacy
API until a host migration identifies the original definition and callbacks.

Keep completed/cancelled state for at least the provider's callback retry
window. Deleting a run also deletes its deduplication history. Never reuse a
deleted run ID. Redis expiration stays opt-in and disabled by default.

## Deferred callbacks

A run-aware `FlowDeferredCompletion` requires the run ID, step ID, attempt,
and event/idempotency ID. Obtain the attempt from the original outbound
operation's `_workflow` execution context; do not substitute whatever attempt
is current when a delayed callback arrives.

Apply callbacks in `mutate()` using the saved definition. An exact duplicate
is a no-op. Conflicting payloads for one event ID, another run, and old
attempts are rejected. Event IDs are scoped to their step and attempt.
Progress may remain pending; it continues waiting for another callback.

Deduplication retains up to 1,024 distinct completion events per run. At the
limit, new events are rejected rather than evicting old receipts and accepting
duplicates. Aggregate noisy provider progress in the host before forwarding
it. Temporal's pending signal inbox has the same bound.

`complete()` changes state only. Core and RPC callers explicitly resume or
schedule a job for newly eligible steps. Resuming never re-executes a waiting
deferred step. The Temporal workflow continues after an accepted signal.

## Retries, completion, and cancellation

The runner visits dependencies before dependents and executes an eligible
step at most once per pass. Completed, skipped, running, and deferred steps
are not executed again. A pending optional step still keeps a run open;
a terminal non-critical failure can permit completion.

An explicit non-retriable result wins over the retry policy. Policies with
`enabled: false`, `idempotent: false`, or exhausted attempts prevent retry.
Unexpected exceptions obey the same attempt limit and backoff as returned
failures. Core hosts schedule another pass after `nextRetryAt`; no background
scheduler is installed. Temporal uses its timers with a bounded retry policy.

`FlowStateTransition::cancel()` stops future work and callbacks. It does not
undo completed side effects or interrupt an operation already executing.
For Temporal, the host uses Temporal's own cancellation API; cancel any
external projection only after the authoritative runtime has acknowledged it.
Replay uses a new run ID. `reset()` remains available only for legacy states,
because resetting a tracked run would make old callbacks ambiguous.

## Execution ownership

Ordinary inline/queue runs use their atomic store as the authority. Persist
the selected `execution_driver` before dispatch. Queue workers read the saved
run and definition. The dispatcher and adapters reject a different driver;
queue/Temporal receipts include the submitted state snapshot for bookkeeping.
Do not blindly save a receipt snapshot after dispatch: a fast worker may have
already advanced the authoritative state.

The core store makes state mutations atomic, not external effects. Hosts own
execution claims, outbox delivery, scheduling, and external idempotency. Do
not perform network calls while holding a database or Redis mutation lock.

The RPC module persists a claim, executes outside the lock, then saves the
result under the same claim. Overlapping resume, completion, or cancellation
requests receive `-32009`. If the worker stops or saving fails, the claim
remains visible through `flow.get`. A host operator must reconcile external
effects before clearing it or replacing the run. Claims do not auto-expire,
because an elapsed timeout cannot prove that an external operation failed.

Temporal's history is authoritative for Temporal runs. Its `snapshot` query
returns current workflow state. A host can copy that state to a read store,
but projections can lag and must not drive execution or callback acceptance.
There is no automatic projection service in this package.

## Authorization and error visibility

The core describes actions; the host authorizes and executes them.
`FlowActionDispatcher` requires an authorizer. RPC is private server-to-server:
its bearer credential grants service access, not per-user permission. A host
must authorize the subject and verify provider webhook authenticity.

Unexpected exceptions become a generic message plus error/correlation IDs.
Use `FlowFailureReporter` for private diagnostics. Business messages, context,
metadata, and definitions supplied by the host are not automatically redacted;
only store data appropriate for that audience. RPC saves initial context for
resume but omits it from responses.

## Upgrading existing integrations

- Start tracked runs with `FlowRun` and `RunScopedFlowStateStore`; leave legacy
  rows alone until their original definition and callback mapping are known.
- RPC `start/get/complete/resume/cancel` now require `run_id`. Completion also
  requires `attempt`. Repeating a start with the same run ID returns that run;
  use another ID to repeat the process for the same subject.
- RPC mutation notifications without a JSON-RPC request `id` do not execute.
  Read notifications still follow JSON-RPC notification semantics.
- Supply the authorizer argument to `FlowActionDispatcher`.
- Replace assertions or UI logic that expected raw executor exception text
  with public error codes and private correlation-based diagnostics.
- Deploy changed Temporal workflow code with your host's worker-versioning
  and history-replay process. A pinned flow definition does not make arbitrary
  PHP workflow code changes replay-compatible. Keep compatible workers for
  existing histories; the package tests do not replace live replay testing.
