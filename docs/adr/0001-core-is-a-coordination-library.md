---
status: accepted
---

# Core is a coordination library, not a durable execution engine

WorkFlow core provides flow definitions, state transitions, evaluation, and adapter contracts, while the host owns scheduling, delivery, and external side effects. We chose this boundary over promising durable end-to-end execution in every host because CLI, Laravel, and standalone deployments have different reliability mechanisms. A runtime may make stronger guarantees explicitly, but the core must not imply automatic retries, exactly-once effects, or durable dispatch that it does not provide; the RPC module therefore exposes interrupted execution claims for reconciliation.
