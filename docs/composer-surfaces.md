# Composer core projection and adapter packages

Core is `webong/workflow`. Laravel, OpenWorkflow and Temporal are independent
packages under `pkg/`; see [package installation and distribution](packages.md).
Core owns neither their autoloading nor Laravel provider discovery. Its runtime
requirement is PHP only.

## Core projection

An independent core ZIP retains examples but excludes adapter payloads. A VCS
install may download the whole monorepo; adapter files there are not autoloaded. A consumer may opt into the `core` file surface to retain only
`src/`, the installed `bin/workflow` authoring CLI, generated language types,
legal files and the complete `docs/` tree. Keeping all guides is intentional: it
includes adapter installation and historical design guidance, without adapter code. It does not retain `pkg/`, tests, native build
sources or the standalone host. There is no Laravel provider stub.

Using the published `webong/composer-source-plugin` 0.5 line, configure the
consumer root (not a dependency's manifest):

```json
{
  "require": {
    "webong/workflow": "<version containing this package layout>",
    "webong/workflow-laravel": "<adapter version>",
    "webong/composer-source-plugin": "^0.5"
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

Plugin 0.5 can activate partway through its first installation, before receiving
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
[projection lifecycle](https://github.com/webong/composer-source-plugin/blob/v0.5.0/docs/projections.md).

## Directory-owned author groups

The root discovery list names four marked JSON descriptors; their nested fields
are grouping metadata, not Composer packages. Require/autoload/bin/discovery
continue to come from each real package's root manifest.

| Descriptor | Selection | Retains and inherits |
|---|---|---|
| `composer.surface.json` | `runtime` | Explicit cross-directory baseline: `src/`, `bin/workflow`, LICENSE, README, CHANGELOG and CONTEXT |
| `types/composer.json` | `types` | Its complete directory, plus `runtime` |
| `docs/composer.json` | `guides` | Its complete directory, plus `runtime` |
| `src/composer.json` | `core` | Its complete directory, plus both `types` and `guides` |

`core` remains the recommended complete selection. The helper selections are
also usable PHP installs: `runtime` has no generated types/guides, `types` adds
only generated types, and `guides` adds only documentation. Every selection keeps
the advertised CLI, engine and legal files. `types` + `guides` has the same
runtime/file payload as `core`. The short root baseline is deliberate: it pins
the engine/CLI closure for every helper without inheritance cycles or sibling
`../` paths. Types and guides use their owning directories automatically, so
new files need no root inventory edits.

All four descriptors survive pruning, including unselected groups' metadata;
this does not retain their unselected content. They must remain in distribution
ZIPs. The archive generator includes git-visible source JSON files, and the
consumer matrix verifies every descriptor after extraction and projection.
Changes to any descriptor invalidate recorded projection state, even for an
unselected group. Restore the descriptor or intentionally update/reinstall to
advance both locks. Nested grouping files are never merged or executed.

The independent `pkg/*/composer.json` manifests are ordinary packages, not
marked groups of core. Their own resources/migrations/discovery remain in the
adapter archives. The root core archive excludes `pkg/`, so this migration does
not add adapter payloads, provider stubs or dependencies to core.

## Author validation

The plugin stays in author `require-dev`; consumers enforcing projection keep
it in runtime `require` and explicitly trust it. No consumer needs the plugin
for an ordinary install. `composer test:projections` uses public plugin 0.5.0
and real independently built archives, each helper, core and unions, adapter
combinations, minimal core, authoritative CLI, real Laravel discovery/migrations
and fresh locked production installs. It checks selected and unselected
changed-descriptor rejection without modifying locks/runtime files, then proves
restoration works. Live Temporal server/replay remains separate acceptance.
