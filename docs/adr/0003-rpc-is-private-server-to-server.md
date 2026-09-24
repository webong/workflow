---
status: accepted
---

# JSON-RPC is a private server-to-server boundary

The standalone JSON-RPC module is for trusted backends, not direct mobile, CLI, or browser access. A client-facing host authenticates the caller and authorizes access to the specific subject and flow before invoking the module. We chose this over a public RPC endpoint because the module's service credential is not a substitute for per-user and per-subject authorization; direct exposure would couple the package to every host's identity and tenancy model.
