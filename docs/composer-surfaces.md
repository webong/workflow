# Composer core projection and adapter packages

Core is `webong/workflow`. Laravel, OpenWorkflow and Temporal are independent
packages under `pkg/`; see [package installation and distribution](packages.md).
Core owns neither their autoloading nor Laravel provider discovery. Its runtime
requirement is PHP only.

## Core projection

An independent core ZIP retains examples but excludes adapter payloads. A VCS
install may download the whole monorepo; adapter files there are not autoloaded. A consumer may opt into the `core` file surface to retain only
`src/`, the installed `bin/workflow` authoring CLI, generated language types,
legal files and core guides. It does not retain `pkg/`, tests, native build
sources or the standalone host. There is no Laravel provider stub.

Using the published `webong/composer-source-plugin` 0.4 line, configure the
consumer root (not a dependency's manifest):

```json
{
  "require": {
    "webong/workflow": "<version containing this package layout>",
    "webong/workflow-laravel": "<adapter version>",
    "webong/composer-source-plugin": "^0.4"
  },
  "config": {"allow-plugins": {"webong/composer-source-plugin": true}},
  "extra": {"source-plugin": {"projections": {
    "webong/workflow": {"surfaces": ["core"]}
  }}}
}
```

The adapter is optional: omit it for engine-only use. Laravel discovery then
comes solely from `webong/workflow-laravel/composer.json`. Install OpenWorkflow
and/or Temporal by their package names instead of selecting adapter surfaces
on core. Each package keeps its namespace and resources in its own archive.

The placeholders require actual archives/repository metadata for this layout.
The earlier `v0.1.0-rc.2` tag has the old shared-manifest surfaces; use its
[versioned documentation](https://github.com/webong/workflow/blob/v0.1.0-rc.2/docs/composer-surfaces.md)
when consuming that immutable release. New adapter publication is separate.

## Bootstrap and locked installation

Plugin 0.4 can activate partway through its first installation, before receiving
an update event. Install it with plugins disabled, then reconcile explicitly:

```sh
composer update --no-plugins --prefer-dist
composer update --prefer-dist
```

Commit `composer.lock` and `composer-source.lock`. A fresh production clone:

```sh
composer install --no-plugins --no-dev --prefer-dist
composer install --no-dev --prefer-dist
composer dump-autoload --classmap-authoritative
vendor/bin/workflow help
```

Keep the plugin in consumer `require` if production projections are needed.
Verify `vendor/webong/workflow/.source-plugin/projection.json`; the bootstrap
pass alone is not proof of pruning. Full installs and `--no-plugins` retain the
complete core archive. Dependency requirements are unchanged by file projection.
Projection downloads a full archive before pruning; independent package
selection is what separates adapter dependencies and downloads.

Prefer dist/artifact installations. A symlinked development checkout must not
be pruned. Widening needs archive restoration (`composer reinstall`), as deleted
files cannot be recreated by autoload generation. See the plugin's
[projection lifecycle](https://github.com/webong/composer-source-plugin/blob/v0.4.0/docs/projections.md).

## Author validation

The root declares only the `core` surface and uses standard path repositories
for its three real development packages. No consumer needs the plugin unless
it requests projection. `composer test:projections` tests independently built
archives, optional adapter combinations, missing adapters, real Laravel
package discovery, the installed CLI and authoritative autoloading. The
minimal core fixture asserts absence of Illuminate, HTTP, jq and Temporal SDK
dependencies. Live Temporal server/replay remains separate runtime acceptance.
