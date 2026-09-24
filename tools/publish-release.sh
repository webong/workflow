#!/usr/bin/env bash
set -euo pipefail

: "${RELEASE_TAG:?RELEASE_TAG is required}"
: "${RELEASE_REPOSITORY:?RELEASE_REPOSITORY is required}"
: "${GH_TOKEN:?GH_TOKEN is required}"
python3 tools/prepare-release.py --tag "$RELEASE_TAG"

# Never replace an already-published release, including its registry tags.
# GraphQL variables must reach GitHub literally, not expand in the shell.
# shellcheck disable=SC2016
existing=$(gh api graphql \
    -f query='query($owner:String!, $name:String!, $tag:String!){repository(owner:$owner,name:$name){release(tagName:$tag){isDraft}}}' \
    -f owner="${RELEASE_REPOSITORY%/*}" -f name="${RELEASE_REPOSITORY#*/}" -f tag="$RELEASE_TAG" \
    --jq '.data.repository.release | if . == null then "missing" else .isDraft end')
if [[ "$existing" != missing && "$existing" != true ]]; then
    echo "Refusing to replace an existing published release." >&2
    exit 1
fi

registry="ghcr.io/${RELEASE_REPOSITORY,,}"
for arch in amd64 arm64; do
    for kind in native rpc; do
        archive="dist/release/workflow-$kind-image-linux-$arch.tar.gz"
        gzip -dc "$archive" | docker load
        image="workflow-$kind:ci-$arch"
        actual=$(docker image inspect "$image" --format '{{.Os}}/{{.Architecture}}')
        if [[ "$actual" != "linux/$arch" ]]; then
            echo "Unexpected image platform: $actual" >&2
            exit 1
        fi
        target="$registry"
        if [[ "$kind" == native ]]; then
            target="$registry-native"
        fi
        docker tag "$image" "$target:$RELEASE_TAG-$arch"
        docker push "$target:$RELEASE_TAG-$arch"
    done
done

for target in "$registry" "$registry-native"; do
    docker buildx imagetools create --tag "$target:$RELEASE_TAG" \
        "$target:$RELEASE_TAG-amd64" "$target:$RELEASE_TAG-arm64"
    docker buildx imagetools inspect "$target:$RELEASE_TAG" --raw |
        jq -e '[.manifests[].platform | .os + "/" + .architecture] | sort == ["linux/amd64", "linux/arm64"]'
done

if [[ "$existing" == missing ]]; then
    gh release create "$RELEASE_TAG" --repo "$RELEASE_REPOSITORY" --verify-tag --draft \
        --title "WorkFlow $RELEASE_TAG" \
        --notes "Linux AMD64/ARM64 distributions. Native SDK bundles remain experimental and require the companion runtime. Docker images: $registry:$RELEASE_TAG and $registry-native:$RELEASE_TAG. See docs/releases.md in this tag for installation, checksums, and limitations."
fi
gh release upload "$RELEASE_TAG" dist/release/*.tar.gz dist/release/SHA256SUMS \
    --repo "$RELEASE_REPOSITORY" --clobber
release_options=(--draft=false --latest=false)
if [[ "$RELEASE_TAG" == *-* ]]; then
    release_options+=(--prerelease)
fi
gh release edit "$RELEASE_TAG" --repo "$RELEASE_REPOSITORY" "${release_options[@]}"
