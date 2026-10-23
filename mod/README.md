# Native modules

`mod/` holds experimental native builds. The JSON-RPC application lives
under [`app/`](../app/README.md).

Both compiler targets compile the same core and the same JSON protocol:

- [TypePHP](typephp/README.md) embeds a PHP runtime and produces a local
  executable and an in-process shared library.
- [elephc](elephc/README.md) compiles the core to native code and produces the
  same two artifacts without an embedded PHP runtime.

The compiler-agnostic front end and the shared parity fixtures live in
[`native/`](native/README.md). Only one copy of each is compiled, so the wire
contract cannot drift between the two modules.
