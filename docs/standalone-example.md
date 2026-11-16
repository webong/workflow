# Standalone testing/reference host

The Go/FrankenPHP host, RPC facade, PDO stores, queue runtime/worker, schemas
and HTTP smoke tests live in `examples/standalone`. PHP classes use
`Webong\WorkFlow\Examples\Standalone`. The Go module is
`github.com/webong/workflow/examples/standalone`.

The unreleased source layout removes `app/`, `Webong\WorkFlow\App` and the
Composer `app` surface. There are no aliases, forwarding files or compatibility
Docker/Compose entrypoints. Use a full installation or repository checkout for
the example; the four PHP projections intentionally exclude it.

The authoring CLI lives at `src/Authoring/FlowAuthoringCli.php` under
`Webong\WorkFlow\Authoring`. `bin/workflow` remains available in core.
The engine and optional adapters do not depend on Gateway or the example.

From the repository root:

```sh
docker compose -f examples/standalone/compose.yml up -d --build
```

See the [example guide](../examples/standalone/README.md) for trusted bootstrap,
worker, authentication and database setup. The example uses its own Compose
project identity; it does not adopt or migrate an existing deployment's volume.
Initialize schemas once, in a fresh test database.

Existing releases, including `v0.1.0-rc.2`, remain immutable. Gateway stays pinned
to that released core until separately updated. This work changes source only:
no release, registry publication, production migration or deployment is implied.
