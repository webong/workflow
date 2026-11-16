# Select installed PHP surfaces with Composer Source Plugin

A normal `composer require webong/workflow` still installs the full package.
No plugin is required to use WorkFlow or read its surface declarations.
Consumers can opt into **file projections** using
[`webong/composer-source-plugin`](https://github.com/webong/composer-source-plugin),
with the supported `^0.4` release line (tested with **0.4.0**).

Projection downloads the full archive, then prunes the installed copy. It does
not reduce network transfer, remove Composer requirements, merge nested
manifests, or turn the selected directories into separately versioned packages.
The engine and optional-adapter namespaces remain unchanged. The unreleased
standalone source move removes the old `app` surface and namespace; released
`v0.1.0-rc.2` remains unchanged and has its own [versioned guide](https://github.com/webong/workflow/blob/v0.1.0-rc.2/docs/composer-surfaces.md).

## Available selections

Every named surface includes the complete core/CLI/discovery baseline, so it
can be selected alone. Multiple surfaces retain the union of their files.

| Surface | Retained functionality and additional files |
|---|---|
| `core` | All `src/`, generated language types, `bin/workflow`, its `Authoring\FlowAuthoringCli` implementation, legal/readme files, core guides and the guarded Laravel discovery provider |
| `laravel` | Core baseline plus **all** `ext/Laravel/`, configuration, migrations, models/concerns, Boost resources and Laravel guide |
| `temporal` | Core baseline plus **all** `ext/Temporal/` and its guide |
| `open-workflow` | Core baseline plus **all** `ext/OpenWorkflow/`, including the pinned schema and CTK fixtures, and OWS guides |

The CLI implementation lives in `src/Authoring`, independently of the standalone
example. Core excludes the RPC server, queue implementation and PDO example
stores. The baseline also retains one Laravel provider
file because Composer advertises that exact discovery class unconditionally.
That provider is inert when the rest of Laravel is absent: no bindings,
configuration or publish targets point at pruned files. Its public namespace
and publication ownership are unchanged in full and Laravel installations.

## Consumer configuration

Add the plugin to the **consumer root's `require`**, not only `require-dev`, if
production `composer install --no-dev` must enforce the projection. Trust it
explicitly in that root. Dependency packages cannot enable plugins for you.

```json
{
    "require": {
        "webong/workflow": "<your chosen version containing these surfaces>",
        "webong/composer-source-plugin": "^0.4"
    },
    "config": {
        "allow-plugins": {
            "webong/composer-source-plugin": true
        }
    },
    "extra": {
        "source-plugin": {
            "projections": {
                "webong/workflow": {
                    "surfaces": ["laravel", "open-workflow"]
                }
            }
        }
    }
}
```

The WorkFlow version above is intentionally a placeholder. Existing release
`v0.1.0-rc.1` predates these declarations; use a release/ref containing them.
Do not copy the plugin README's `^1.0` examples: that is not the verified
available release line for this integration.

With plugin 0.4, explicitly disable plugins for the bootstrap installation.
Otherwise a newly installed plugin can activate before receiving the update
event and reject the missing projection state. Then record the selection:

```sh
composer update --no-plugins --prefer-dist
composer update --prefer-dist
```

Commit **both** `composer.lock` and `composer-source.lock`. A fresh locked
installation likewise requires the documented bootstrap/reconciliation passes:

```sh
composer install --no-plugins --no-dev --prefer-dist
composer install --no-dev --prefer-dist
```

Use an optimized/authoritative autoloader normally after projection:

```sh
composer dump-autoload --classmap-authoritative
vendor/bin/workflow help
```

Without the plugin, with `--no-plugins`, or without a projection selection, the
full package remains. Never treat a first bootstrap pass as proof of pruning;
verify the installed `.source-plugin/projection.json` marker. Prefer dist
installs. A symlinked path repository is not safe for projection and is rejected
by the plugin; copied/artifact installations are suitable.

Narrow selections with an intentional `composer update`. Widening a pruned
installation cannot recreate deleted files: restore the archive with
`composer reinstall webong/workflow` and follow the plugin's state diagnostics.
A locked install/dump-autoload validates the recorded selection rather than
silently accepting changed allowlists. See the plugin's
[projection lifecycle](https://github.com/webong/composer-source-plugin/blob/v0.4.0/docs/projections.md).

## Integration dependencies and combinations

Selections are file closures, not dependency profiles. The root's existing
PSR HTTP and Zest jq requirements remain installed even for `core`. Optional
host dependencies still need to be required by the consumer:

- `laravel`: the relevant Illuminate components/Laravel host; Redis support
  additionally needs the configured Redis/cache driver; sortable definition
  records require `spatie/eloquent-sortable` as documented in the Laravel guide.
- `temporal`: install/configure `temporal/sdk` and its worker/runtime to run
  real Temporal workflows. The projection integration test exercises the
  SDK-neutral activity handler, not live Temporal server/replay behavior.
- `open-workflow`: JSON definitions work without YAML support; require
  `symfony/yaml` for YAML input. HTTP/OpenAPI tasks need a host PSR-18 client.

Supported combinations include each surface alone and arbitrary unions of the
four named surfaces. For Laravel OWS binding, select **both** `laravel` and
`open-workflow`; Laravel without OWS does not register a missing OWS singleton.
For OWS-bearing Temporal definitions, select **both** `temporal` and
`open-workflow`; the Temporal handler's OWS context-restoration path needs that
adapter.
Selecting a surface does not enable a runtime, executor, broker or database.

The full installation remains the path for authoring/release tooling and
native builds. Projected PHP surfaces do not retain `mod/`, repository tests,
CI workflows, `examples/standalone` or the complete build/release toolchain.
Use an unprojected checkout/full installation for the standalone testing host;
it has no Composer surface. See [standalone example](standalone-example.md).

## Author configuration and development

`composer.json` declares `extra.source-plugin.surfaces`. The baseline include
list is repeated in each integration because plugin 0.4 has no surface
inheritance/dependency mechanism. Keep that closure synchronized when adding
unconditional providers, binaries, resources or cross-module calls.

WorkFlow uses the plugin only as an author **development dependency** with an
explicit allow entry. It declares no self-projection and no workspaces.
`ext/` modules do not have independent Composer identities/releases today, and
native build manifests under `mod/` are build tools, not application workspace
packages. A later package split needs its own dependency/versioning design.

Run the installation regression matrix inside the PHP 8.3 test environment:

```sh
composer test:projections
```

It builds a ZIP from the actual worktree (the only fixture metadata change is
a synthetic version), installs real consumer roots with plugin 0.4.0, checks
all named surfaces and a combined selection, then tests lock replay and
`--no-plugins`. It uses authoritative autoloading, executes the installed CLI,
runs core/OWS/SDK-neutral Temporal behavior and the full-install standalone example, and boots real Laravel package
discovery with and without its retained integration resources. Development
checkouts are never pruned.
