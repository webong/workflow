# Independent Composer packages

| Directory | Composer identity | Owns |
|---|---|---|
| repository root | `webong/workflow` | Engine, authoring CLI and core projection |
| `pkg/Laravel` | `webong/workflow-laravel` | Laravel autoloading, discovery, Illuminate/Spatie dependencies, config, migrations and Boost resources |
| `pkg/Rules` | `webong/workflow-rules` | Bounded portable rule definitions; PHP only |
| `pkg/Conditions` | `webong/workflow-conditions` | Rule/jq predicates, boolean composition and opt-in flow guards |
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
independent packages. A fresh `composer install` resolves them without a plugin
bootstrap or network publication. Root dependencies do not merge nested
manifests: Composer installs each package with its own autoload/discovery data.
The development branch alias identifies the root as `0.1.x-dev`. In an archive
without Git metadata, use `COMPOSER_ROOT_VERSION=dev-main` when developing the
monorepo. The standalone Docker build uses its own consumer manifest, installing core
as a dependency without resolving the monorepo development adapters.

## Build and install external artifacts

Adding nested manifests does not make GitHub's root VCS repository serve the
independent package identities. Build independent ZIP distributions:

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

## Source Plugin publication

Composer Source Plugin owns subpackage splitting, versioning and index generation. Root `extra.source-plugin.workspaces` selects `pkg/*`; each package retains its own Composer identity and dependency graph. The root engine remains the ordinary repository-root package. Local ZIP fixtures above verify package isolation; they are not the production publication mechanism.

After committing source changes, inspect the selected packages and preview publication:

```sh
composer source:publish list
composer source:publish release --release-version=0.1.0-rc.3 --no-metadata-branch --out-dir=composer --dry-run
```

The publisher reads committed manifests. Its release command splits the selected subpackages and writes the Composer index; publication uses its explicit push flow. Keep the index on the normal branch at `composer/composer-packages.json`. Consumers register that standard Composer index (or discover it through a Source Plugin catalog) and require subpackages normally. Do not hand-maintain package split branches or index records.

A coordinated clean root engine release is required for the Laravel, OpenWorkflow and Temporal adapters' `^0.1.0-rc.3` constraint. Existing `v0.1.0-rc.2` remains immutable. Source configuration and local preview do not publish tags, package refs, releases or index metadata.

## Conditions and rules

[Rules](../pkg/Rules/README.md) can be installed without the engine or Illuminate. [Conditions](../pkg/Conditions/README.md) uses Illuminate Validation without requiring a Laravel application and composes rules with jq. Its optional guard decorator uses existing core contracts compatible with rc.2. The new rules, jq and conditions package identities are distributed through the Source Plugin subpackage index.
