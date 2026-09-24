# WorkFlow native SDK bundle

This experimental SDK contains the native executable, C shared library,
PHPX runtime library, C header, Node/Python examples, build dependency lockfile,
license notices, and a version/platform manifest.

It is **not a static or self-contained binary distribution**. The matching
`ghcr.io/webong/workflow-native:<version>` Docker image supplies PHP and the
Debian Bookworm system libraries. CI tests the archive after unpacking it
into that runtime image, separately on Linux AMD64 and ARM64.

Use the matching version of that image for the supported runtime. Native host
installation requires compatible PHP ZTS/libphp, GMP/MPFR, and the transitive
libraries used by the build. A different distribution, libc, or PHP build is
not covered by these tests. Do not load this library into an existing PHP
runtime. The PHP Composer package does not need this SDK.

The image's default command reads one JSON request from stdin and prints one
JSON response. The executable is at `/opt/workflow/bin/workflow-native`; the
shared library is at `/opt/workflow/lib/libworkflow_native.so`. The image sets
`LD_LIBRARY_PATH` to include the bundled PHPX library. It runs as a non-root
user and does not include TypePHP, a C++ compiler, Node, or Python.

For Node, copy `examples/node_client.mjs` into your application and pass the
executable's absolute path as the third argument to `call()`. For Python,
copy `examples/python_client.py` and pass the library's absolute path to
`WorkflowNative`. The example defaults under `/build` apply only to the
development container. Install your language runtime in a derived image or
provide the corresponding native dependencies in your own deployment.

Your application owns durable state, concurrency control, work delivery,
and external idempotency. The C library must be initialized, called, and
closed on the same OS thread; shutdown is terminal. See the source repository's
`docs/languages.md` and `mod/typephp/README.md` for the complete contract.

WorkFlow is MIT licensed. Linked components and the build compiler have their
own licenses; see `licenses/` and `manifest.json`. Including the compiler's
notice does not mean the compiler is shipped in the runtime image. Upstream
runtime images retain their system package notices and PHP source archive.
Review the licenses of all components when redistributing a derived product.
