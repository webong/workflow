# Callbacks target an attempt and replay starts a new run

Callbacks identify the run, step, attempt, and event, and exact duplicates are idempotent while conflicting or stale events are rejected. Replaying an entire process creates a new run instead of resetting counters, because a late callback must never complete unrelated replacement work. Cancellation retains the run as a terminal record; host retention must cover the external retry window.
