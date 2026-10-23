# Shared native protocol

`mod/native/` holds the compiler-agnostic front end that every native module
compiles. It is required by [TypePHP](../typephp/README.md) and
[elephc](../elephc/README.md) alike.

- `php/NativeFlow.php` — the JSON protocol over the core (`start`, `evaluate`,
  `advance`, `complete`, `cancel`). It is stateless coordination only; the
  foreign host owns storage and side effects.
- `php/api.php` — `workflow_native_dispatch(string): string`, the single entry
  both compilers expose.
- `php/main.php` — the stdin body the compiled executable runs.
- `php/reference.php`, `php/autoload.php` — interpreter-only oracle wiring; never
  compiled into a native artifact.
- `tests/export-cases.php` — the PHP 8.3 parity fixtures shared by every module.

Keep this the only copy. The flow rules stay in [`src/`](../../src); neither
native module reimplements them in C++ or another language. Anything specific to
one compiler — the `#[Export]` entry, the C++ bridge, the build manifest — lives
in that module's directory.
