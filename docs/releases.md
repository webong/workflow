# Builds, distributions, and releases

The CI pipeline tests the PHP package and builds **Linux AMD64 and ARM64**
distributions. This is an explicit support matrix, not a promise that a
binary works on every Linux distribution or operating system.

## What runs on each push and pull request?

| Job | What it proves |
| --- | --- |
| Validation | Actions/shell syntax and release-asset validation tests |
| PHP 8.3 | Composer validation, PHPStan, PHPUnit with PostgreSQL/Redis, generated TypeScript contracts |
| Native AMD64 / ARM64 | TypePHP compilation, 41 PHP 8.3 parity cases, C ABI ownership/thread checks, six Node tests, then the same checks after unpacking the SDK into a clean runtime |
| RPC AMD64 / ARM64 | Go unit tests/vet, FrankenPHP image build, HTTP authentication and lifecycle tests with PostgreSQL |

Each architecture runs on its own native GitHub runner. The compiler stays
in a separate PHP 8.5 toolchain; it does not replace the PHP 8.3 baseline.
The final native image runs as a non-root user and contains neither the
compiler nor the language runtimes used for tests (Node/Python).

Successful native jobs upload `distribution-native-amd64` and
`distribution-native-arm64` artifacts to the Actions run, retained for seven
days. Each contains an SDK archive and its SHA-256 checksum. A green PHP job
alone does not imply either native or RPC builds passed.

## What gets published?

Only a pushed version tag such as `v0.1.0` or `v0.1.0-rc.1` enables publishing,
and only after **every required job and architecture succeeds**. Branch
pushes, pull requests, and manual runs do not publish images or releases.

| Distribution | Contents |
| --- | --- |
| `workflow-native-linux-{amd64,arm64}.tar.gz` | Executable, C shared library, PHPX library, C header, examples, licenses, dependency/platform manifest |
| `workflow-native-image-linux-{amd64,arm64}.tar.gz` | Docker-save archives of the tested native runtime images, including PHP/system runtime libraries |
| `workflow-rpc-image-linux-{amd64,arm64}.tar.gz` | Docker-save archives of the tested Go/FrankenPHP server images |
| `SHA256SUMS` | Checksums of all six archives |

The SDK tarballs are **not self-contained**: use the matching native Docker
runtime or supply its compatible dependencies yourself. Only the Docker
runtime is tested as the packaged deployment environment. It is based on
Debian Bookworm/glibc, not Alpine/musl. The archives include relevant license
notices; upstream runtime images retain their PHP sources and system notices.

The same tested images are loaded and pushed—not rebuilt in the publishing
job—then combined into two multi-architecture GHCR tags:

- `ghcr.io/webong/workflow:<version>`: the RPC server.
- `ghcr.io/webong/workflow-native:<version>`: the native executable/runtime.

Tags include the leading `v`. Architecture-specific tags append `-amd64` or
`-arm64`. No `latest` Docker tag is moved automatically, and GitHub releases
are not automatically promoted to latest. Tags containing a prerelease suffix
are marked as prereleases.

Release assets are staged in a draft, then published after image manifests
have both architectures. The job refuses to replace an already-published
release. A failed publish can leave architecture tags or a draft; rerunning
the same workflow may complete an unpublished draft. Inspect the failure
before retrying. Never move an existing release tag to different source code.

## Download and run

Choose an existing version from GitHub Releases; do not assume the example
version below has already been released.

```sh
WORKFLOW_VERSION=v0.1.0
docker pull "ghcr.io/webong/workflow-native:$WORKFLOW_VERSION"
printf '%s' '{"protocol":1,"operation":"start","run_id":"example-1","definition":{"key":"empty"}}' | \
  docker run --rm -i "ghcr.io/webong/workflow-native:$WORKFLOW_VERSION"
```

Docker selects the matching architecture. The result should have `ok: true`
and state status `completed`. This image handles one JSON request per process;
it is not an HTTP server. For the server image, follow the [RPC guide](../mod/README.md)
to supply a trusted bootstrap, PostgreSQL connection, and bearer token.

To use downloaded archives offline, first verify `SHA256SUMS` in the directory
containing all six files, then load the appropriate image:

```sh
sha256sum -c SHA256SUMS
gzip -dc workflow-native-image-linux-amd64.tar.gz | docker load
```

The Docker-save archive retains the tested local tag `workflow-native:ci-amd64`
(or `workflow-rpc:ci-amd64` for RPC). You can retag it locally after loading.
The SDK bundle's README explains the native library paths and caller contract.
Checksums detect corrupted files; they are not a separate signature or
independent attestation of provenance.

## Cut a release

Review the target commit and its full green CI run first. Choose a new version,
update the changelog as appropriate, and push that tag. The pipeline does not
create a version tag for you.

Publishing uses the repository's `GITHUB_TOKEN`: the publish job alone has
`contents: write` and `packages: write`. No PAT is required by this workflow.
Repository/organization policy must permit GHCR publishing. New GHCR packages
may require a maintainer to make them public and configure repository access;
repository visibility alone is not a guarantee of anonymous package access.
Build/test jobs have read-only repository permissions and do not log in to GHCR.

Native builds remain experimental. macOS, Windows, musl, static libraries,
fully static executables, npm/pip SDK publishing, and automatic Packagist
registration are not part of this pipeline. Broader memory/stress testing and
redistribution review are still needed before claiming production support.
