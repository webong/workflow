---
status: proposed
---

# elephc as a second native compiler target

We add `mod/elephc` as a second, experimental native target alongside
`mod/typephp`, and extract the compiler-agnostic JSON front end into
`mod/native/` so both targets compile one copy. elephc compiles a static subset
of PHP to native code with no embedded runtime, which removes the PHP/PHPX
runtime dependency the TypePHP artifacts carry, at the cost of a stricter
compile-time boundary over `src/`.

## Context

The TypePHP module proved the protocol: a stateless `string → string` JSON
front end (`start`/`evaluate`/`advance`/`complete`/`cancel`) over the existing
PHP core is enough for a Node, Python, or C host to own a workflow's state and
side effects locally. That module also carries real costs:

- It embeds a PHP runtime (swoole/phpx), so artifacts need matching PHP ZTS,
  GMP/MPFR, and `LD_LIBRARY_PATH`. The SDK archive is explicitly **not**
  self-contained and ships a companion runtime image.
- It builds only inside Docker on Linux, so the repository cannot produce or
  test a native artifact on an Apple Silicon host.
- The wire contract, its oracle, and the executable body are duplicated per
  compiler the moment a second one appears.

elephc is a from-scratch compiler for a static subset of PHP: parse, type-check,
lower to native assembly, assemble, link, with no VM or Zend Engine. Relevant to
this repository:

- **`--emit cdylib` generates a C ABI and the header.** `#[Export]` on a
  top-level function becomes a C symbol; the compiler writes a deterministic
  header and a boundary API (`elephc_init`, `elephc_shutdown`,
  `elephc_abi_version`, `elephc_last_status`, `elephc_last_error`,
  `elephc_free`). TypePHP needs a hand-written `cpp/bridge.cc` and
  `include/workflow_native.h` for the same result.
- **The supported export parameter set is exactly our protocol's shape.** Only
  fixed lists of `int`/`float`/`bool`/`string` can cross the boundary; arrays,
  objects, callables, nullable, variadic, and by-reference do not. A single
  `string` in and `string` out is the shape elephc marshals.
- **It documents the constructs the core uses.** `json_*`, `hash`, `date`,
  array builtins, `bin2hex`, `iterator_to_array`, `readonly`, enums, named
  arguments, first-class callables, and `match` are all supported. The core uses
  no reflection, `eval`, variable variables, `->{$name}`, or generators.
- **Its contract is fail-loud.** Unsupported behavior is a compiler error, not a
  silent fallback, and it rejects `exit`/`die` transitively reachable from an
  export.
- **macOS is a first-class host.** A macOS ARM64 binary or dylib can be built and
  run on a developer machine with no container.

Constraints, also relevant:

- Five targets: macOS ARM64, Linux ARM64, Linux x86_64, and iOS ARM64 library
  targets. No Windows, no musl.
- The ABI is single-threaded with no per-host runtime context — the same
  practical restriction TypePHP has (one runtime, one owning OS thread).
- One `.php` entry source per cdylib; included/required files still work.
- Autoloading is resolved at compile time from Composer metadata, not through a
  runtime `spl_autoload_register` closure.

## Decision

1. **Extract the shared front end.** Move `mod/typephp/php/*` to
   `mod/native/php/` and `mod/typephp/tests/export-cases.php` to
   `mod/native/tests/`. Both modules compile that one copy; the wire contract and
   the parity fixtures cannot drift. TypePHP's manifests, Dockerfile, and compose
   file point at the new paths. The core flow rules stay in `src/`.
2. **Add `mod/elephc`.** A `#[Export]` entry (`php/workflow_native.php`), an
   executable entry (`php/main.php`), a host build script, a generated header at
   `include/libworkflow_native.h`, and Python/Node/C consumers. No C++ bridge and
   no hand-written header.
3. **Do not put elephc in CI or releases yet.** The compiler is not installed on
   the runners and the build has not been exercised. `make elephc-build` and
   `make elephc-test` are host commands, and parity requires a PHP 8.3
   interpreter for the oracle.
4. **Keep both modules experimental.** Neither replaces the Composer package or
   the JSON-RPC application.

## Consequences

- The protocol and its fixtures have a single source, so a change lands once.
- The elephc artifact has no embedded PHP runtime, which simplifies the
  distribution story if it is ever packaged.
- Developers on macOS can build and test a native artifact without Docker.
- A second native lifecycle API exists, so clients differ (`workflow_native_*`
  versus `elephc_*` plus the generated export). The READMEs document each.
- `mod/typephp` gains a cross-directory dependency on `mod/native/`, so its
  Dockerfile must copy that directory.

## Known risks

- **Compiling `src/` in the static subset is unproven.** Expect the compiler to
  demand explicit narrowing where a `mixed` value flows into a typed object
  parameter, which the core does heavily through `fromArray()` factories.
- **`random_bytes()`** is used once for a correlation ID and does not appear in
  elephc's builtin inventory; it may need a substitute.
- **The build is unverified.** `elephc --check` on the entry is the cheapest way
  to learn how many fixes stand between here and a working module; it should be
  the first step before promoting this ADR to `accepted`.

## Follow-ups

- Run `elephc --check` from the repository root and record the diagnostics.
- Decide where a Linux SDK archive and its licensing/manifest live once a build
  succeeds, mirroring `mod/typephp/package.py` minus the PHPX/PHP/GPL notices.
- Consider a CI job that installs elephc on a Linux runner to keep the module
  honest.
