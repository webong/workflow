---
status: accepted
---

# Queue runtime owns state, claims and outbox

The reference queue runtime owns its run snapshots, claims and publication outbox in one database. An arbitrary `FlowStateStore` cannot participate atomically in a PDO transaction, so the PDO implementation belongs in `app/`, with a dedicated schema and no external store argument; framework-neutral eligibility and claim contracts remain in `src/`.

All mutations take one database mutex before reading state. This deliberately trades scheduling throughput for portable, testable correctness across SQLite, PostgreSQL and MySQL: claims, global capacity, attempt changes, cancellation and outbox writes commit together. Effects and publication run after commit. Hosts requiring higher throughput can implement the same contract with finer locks.

A claim ID fences one attempt; a separate stable run/step key identifies an external effect across attempts. Delivery is at least once. Lease loss permits bounded retry only for a declared idempotent policy; otherwise the unknown outcome requires reconciliation. The database cannot guarantee exactly-once external effects, terminate an already running process, or replace provider-side idempotency.

RPC keeps an immutable routing reservation for its selected runtime and pinned definition/context, then initializes the queue outside the registry lock. Queue RPC reads and mutations address queue authority directly; interrupted initialization fails closed and can retry the same reservation, so no distributed transaction or progress projection is required. A private storage ID scopes subject, flow and logical run identity while the public run ID stays unchanged. A stored scope check prevents a registry collision from selecting another subject's context; MySQL registries require case-sensitive subject keys.
