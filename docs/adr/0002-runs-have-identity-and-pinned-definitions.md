---
status: accepted
---

# Runs have explicit identity and pin a definition version

A Flow Run has its own identity rather than being permanently identified by subject and flow key, while a one-active-run rule remains a host policy. Each run pins its complete Flow Definition snapshot so later edits cannot silently change an in-progress run, even if an author reuses a version number. A core run-scoped store composes with existing adapters, preserving legacy data without guessing which historical definition it used; the host owns publication policy.
