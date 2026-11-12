# WorkFlow Manticore native bundle

This experimental bundle would contain the Manticore-built executable:

- `workflow-native` — reads one JSON request from stdin, prints one JSON
  response. No PHP runtime, no interpreter, no extension loader.

Unlike the [TypePHP SDK](../typephp/DISTRIBUTION.md), it is **not tied to a
matching PHP runtime image**. Manticore links libc plus the system libraries
the stdlib rides on (PCRE2, OpenSSL 3, zlib), and the emitted binary carries
none of PHP itself.

What it still is **not**:

- A cross-platform binary. Each artifact targets one platform
  (Linux glibc arm64 / x86_64, macOS arm64 / x86_64; musl via Alpine). Match
  the artifact to the host platform and libc family.
- A self-contained operating-system image. The artifact links the host's
  pcre2, openssl, and zlib, so those system libraries must be present.
- A C-ABI shared library. Manticore's `--emit-library` produces a `.o`
  object; wrapping it into `libworkflow_native.so` is a stretch goal.
- A tested release. CI does not build or publish an Manticore SDK archive yet.

Your application owns durable state, concurrency control, work delivery, and
external idempotency. The executable is a fresh process per call and speaks
JSON over stdin/stdout; there is no in-process C ABI yet. See the source
repository's `docs/languages.md` and `mod/manticore/README.md` for the
complete contract.

WorkFlow is MIT licensed, and so is Manticore, which simplifies redistribution
versus the TypePHP path: there is no embedded PHP runtime or GPL-licensed
compiler to notice in the bundle.
