# Native modules

`mod/` holds experimental native builds. The JSON-RPC application lives
under [`app/`](../app/README.md).

All compiler targets compile the same core and the same JSON protocol:

- [TypePHP](typephp/README.md) embeds a PHP runtime and produces a local
  executable and an in-process shared library.
- [elephc](elephc/README.md) compiles the core to native code and produces the
  same two artifacts without an embedded PHP runtime.
- [Manticore](manticore/README.md) compiles PHP to LLVM IR (via clang) into a
  standalone native executable, with no embedded PHP runtime.

The compiler-agnostic front end and the shared parity fixtures live in
[`native/`](native/README.md). Only one copy of each is compiled, so the wire
contract cannot drift between the two modules.
