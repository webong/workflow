# elephc 0.27.5 diagnostics

These are failing compatibility probes, not passing conformance tests.
Run from the repository root with elephc 0.27.5 on Linux ARM64:

```sh
elephc --check mod/elephc/tests/compiler/export_method.php
elephc --check mod/elephc/php/workflow_native.php
```

The first probe has no WorkFlow dependencies. It fails with:

```text
export 'echo_request' reaches opaque invocation 'method_call' through echo_request;
the cdylib boundary cannot prove process termination is unreachable
```

An exported call to an ordinary concrete class method is enough to reproduce
this. Changing constructor parameters to `mixed` or adding array adapters cannot
resolve this boundary restriction. Do not disable the boundary safety check.
Upstream describes the restriction at https://elephc.dev/docs/beyond-php/cdylib/.

The core check currently stops at `FlowRunner::run`: the result of the typed
`FlowStepExecution::execute(): StepState` call is inferred as `mixed` when passed
to `FlowState::withStep`. An experimental redundant `instanceof StepState` guard
gets past that check, but then the export boundary fails as above. That guard
was removed because PHPStan correctly identifies it as always true.

Further executable-only probes reached code generation, including an unresolved
`_fn_main` link reference with the repository entry point. A temporary flattened
source with a renamed entry function linked, but did not pass a valid `start`
request. Those probes were discarded; successful linking is not parity evidence.

Validated separately: PHP unit tests and PHPStan; TypePHP executable and shared
library against the shared PHP 8.3 oracle, ABI lifecycle/buffer/thread checks,
and Node client tests. Re-run all of them after a compiler upgrade. No elephc
runtime, SDK archive, C host, or Python library client is verified yet.

The bootstrap declares only the native protocol's dependencies. Its local
`random_bytes` fallback uses elephc's `random_int`/`chr` solely for error
correlation IDs; it is not a replacement for the host PHP builtin and is not
loaded by the Composer or TypePHP paths.
