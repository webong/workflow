---
status: accepted
---

# Ready sets, child runs, and host-owned dispatch

WorkFlow exposes concurrency and composition as coordination data without making a scheduler part of core. `FlowReadySet` returns every step eligible from one state snapshot; a driver may run them sequentially or atomically claim and fan them out. Child workflows cross the `FlowChildRunDispatcher` boundary with a parent-attempt identity, and an awaited child leaves its parent step deferred until the host returns an ordinary attempt-scoped completion. Core owns neither worker concurrency nor child-definition lookup.

The standalone RPC service may start any execution driver registered by its trusted bootstrap. It records the chosen driver before dispatch, dispatches outside the state lock, and persists the receipt without replacing state written by a fast async worker. Repeating `flow.start` is idempotent; an explicit async `flow.resume` re-dispatches the same execution identity, so its driver must make that operation idempotent.

OWS `wait`, `listen`, and `run.workflow` use these seams. A wait records a successful deadline outcome, while ordinary deferred deadlines still fail through `timedOut()`; `deadlineReached()` applies the recorded outcome. Listen only records the subscription and child runs only dispatch an identity: the host or Temporal adapter still owns timers, event correlation, workers, and callback authentication.
