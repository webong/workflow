# Execution runtime owns authoritative state

Ordinary runs use their atomic store as the authority; Temporal runs use Temporal history, with external stores serving only read projections. This avoids conflicting decisions from independently updated state and keeps replay deterministic. Projections may lag, and callbacks or cancellation must reach the authoritative runtime.
