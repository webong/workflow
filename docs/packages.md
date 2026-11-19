# Independent Composer packages

| Directory | Composer identity | Owns |
|---|---|---|
| repository root | `webong/workflow` | Engine, authoring CLI and core projection |
| `pkg/Laravel` | `webong/workflow-laravel` | Laravel autoloading, discovery, Illuminate/Spatie dependencies, config, migrations and Boost resources |
| `pkg/Jq` | `webong/workflow-jq` | Raw jq evaluation/backends/parser; PHP and Zest only, no engine dependency |
| `pkg/OpenWorkflow` | `webong/workflow-open-workflow` | OWS translator/runtime/schema/CTK, PSR HTTP, YAML and the standalone jq package |
| `pkg/Temporal` | `webong/workflow-temporal` | Temporal workflow/activity classes and Temporal SDK dependency |

Core requires PHP only. A VCS install of the monorepo root may still download
unloaded adapter sources; the independent core ZIP excludes them, and the
optional core projection prunes them from a VCS/dist installation. Installing it never advertises a Laravel provider or
loads adapter namespaces. The three workflow adapters require the forthcoming clean core `^0.1.0-rc.3`; their PHP
namespaces remain `Webong\WorkFlow\Laravel`, `Webong\WorkFlow\OpenWorkflow`
and `Webong\WorkFlow\Temporal`. Install OpenWorkflow alongside Laravel or
Temporal when those integrations handle OWS definitions. No adapter implicitly
installs another adapter.

## Development checkout

Core file groups are owned by descriptors under `src/`, `types/` and `docs/`,
plus a small root runtime baseline. Those are not package manifests;
`pkg/*/composer.json` owns the real independently installable adapters.

The root uses Composer path repositories and development requirements for the
four real packages. A fresh `composer install` resolves them without a plugin
bootstrap or network publication. Root dependencies do not merge nested
manifests: Composer installs each package with its own autoload/discovery data.
The development branch alias identifies the root as `0.1.x-dev`. In an archive
without Git metadata, use `COMPOSER_ROOT_VERSION=dev-main` when developing the
monorepo. The standalone Docker build uses its own consumer manifest, installing core
as a dependency without resolving the monorepo development adapters.

## Build and install external artifacts

Adding nested manifests does not make GitHub's root VCS repository serve the
adapter and jq package identities. Build independent ZIP distributions:

```sh
php tools/package-composer.php dist/composer 0.1.0-rc.999
```

`0.1.0-rc.999` is a local verification version, not a published release. The
command packages the current source, adds the chosen version to each archive's
manifest, includes package licenses/resources and excludes `pkg/` from the core
archive. It writes files only; it does not publish or register packages. Repeated
builds of unchanged source produce matching archives.

Point a fresh consumer at that directory:

```json
{
  "repositories": [{"type": "artifact", "url": "/absolute/path/to/dist/composer"}],
  "require": {"webong/workflow-laravel": "0.1.0-rc.999"},
  "minimum-stability": "RC"
}
```

Composer resolves the separate core archive through the adapter's declared
requirement. You may instead require multiple adapters in the consumer root.
Ordinary installs need no Source Plugin. Production installs use normal
`composer install --no-dev`; each package brings its required dependencies.
Core projections remain an optional consumer-root policy (see
[Composer surfaces](composer-surfaces.md)).

Future publication must host the adapter ZIPs in Composer repository metadata
(or split repositories) under their own names and actual release versions.
The minimum excludes rc.2, which embeds adapter classes/discovery and would
create competing package ownership. A matching clean core release must be
published before these new adapters can use public version constraints.
These new adapter packages are not yet published on Packagist or GitHub
Releases. Existing tags, including `v0.1.0-rc.2`, retain their original layout;
use that tag's documentation when consuming it. This source preparation does
not update Gateway's released core pin or publish any registry metadata.

## Validation

`composer test:projections` builds these actual ZIPs and installs clean external
roots: minimal core, Laravel host without adapter, each adapter alone, combined
adapters, core projection, locked production replay and disabled plugins. It
uses authoritative autoloading, the installed CLI, real Laravel discovery and
migrations, OWS execution/schema resources and real Temporal SDK attributes and
SDK-neutral execution. Live Temporal server/history replay is outside this
package-install test; host runtime setup remains required.

The standalone jq package can be installed alone; its pure-PHP and native
backends are documented in [pkg/Jq](../pkg/Jq/README.md). OpenWorkflow owns
`$workflow`/`$task`/`$context` preprocessing; jq itself owns only raw input and
query execution. Core and the Laravel/Temporal adapters do not pull jq in unless
they also install OpenWorkflow or explicitly require jq.
