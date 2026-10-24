# elephc 0.27.5 diagnostics

These are failing compatibility probes, not passing conformance tests.

Upstream reports:

- [#2034: loop return-type inference](https://github.com/illegalstudio/elephc/issues/2034)
- [#2035: exported class-method call](https://github.com/illegalstudio/elephc/issues/2035)
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

## Traced loop return-type failure

`loop_return_type.php` reduces the `FlowRunner::run` failure to 14 lines, with
no WorkFlow dependencies. PHP executes it successfully; elephc 0.27.5 reports:

```text
Method State::withStep parameter $next expects State, got mixed
```

```sh
php mod/elephc/tests/compiler/loop_return_type.php
elephc --check mod/elephc/tests/compiler/loop_return_type.php
```

The declared return type of `execute()` is `State`. The trigger is the combination
of a guarded `mixed` loop value passed to that function and assigning the next
state back to the loop-carried `$state` variable.

Verified controls on the same compiler:

- Change `$state = $state->withStep($result)` to `$state->withStep($result)`:
  the type check passes. This is a diagnostic control, not a valid application fix.
- Pass `new Step()` to `execute()` instead of the narrowed loop variable:
  the type check passes.
- Move the `execute()` / `withStep()` pair into a helper accepting `Step` and
  `State`, returning `State`: the type check passes while retaining state updates.

### Compiler source trace

Inspected upstream tag `v0.27.5`, commit
`e2db603f8732c1ab2fc789adc0c8e7b4a7f19a1e`:

1. `src/types/checker/stmt_check/control_flow.rs:58-86` runs
   `loop_carried_storage_types` with a callback to the **live, mutable** checker's
   `infer_type`. Inference errors become `None` through `.ok()?`.
2. `src/types/checker/loop_storage.rs:138-165` collects assignment evidence without
   the branch guard's narrowing. Failed inference becomes `Mixed`; repeated passes
   can consequently make the analysis environment's `$state` receiver `Mixed`.
3. `src/types/checker/inference/objects/methods.rs:136-152` treats a method call on
   that `Mixed` receiver as unresolved and records argument reference aliases.
4. `src/types/checker/mod.rs:1058-1082` inserts the result argument into
   `boxed_ref_aliased_locals`. This mutates checker state outside the speculative
   loop environment; the loop-analysis callback does not restore it.
5. `src/types/checker/stmt_check/assignments/locals.rs:922-933` subsequently forces
   an aliased local to `Mixed`, even when its initializer has a declared concrete
   return type. The later `withStep()` argument check rejects that `Mixed` value.

This source path explains the differential reproduction: speculative loop
analysis leaks alias/storage state into ordinary checking. A compiler correction
should isolate speculative inference effects and cover this case with a regression
without removing conservative alias handling from real unresolved calls. No patched
compiler has been built or validated here.

A temporary WorkFlow probe moved execution and `withStep()` into a typed
`executeAndStore()` helper. The original diagnostic disappeared and the full entry
reached the independent `opaque invocation 'method_call'` cdylib failure above.
The probe was reverted; this trace changes only diagnostics, not production logic.

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
