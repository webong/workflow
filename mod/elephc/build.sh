#!/bin/sh
# Build the experimental elephc artifacts. Run from the repository root.
#
#   sh mod/elephc/build.sh            # host target
#   sh mod/elephc/build.sh linux-x86_64
#
# Set ELEPHC to use a compiler other than the one on PATH.
set -eu

ELEPHC="${ELEPHC:-elephc}"
TARGET="${1:-}"
SRC="mod/elephc/php"
BUILD="mod/elephc/build"
INCLUDE="mod/elephc/include"

command -v "$ELEPHC" >/dev/null 2>&1 || {
    echo "elephc not found. Install it first: https://elephc.dev" >&2
    echo "  curl -fsSL https://get.elephc.dev | sh && elvm install latest && elvm use latest --global" >&2
    exit 1
}

[ -f composer.json ] || {
    echo "Run this script from the repository root so elephc can read the Composer autoload metadata." >&2
    exit 1
}

target_args=""
if [ -n "$TARGET" ]; then
    target_args="--target $TARGET"
fi

rm -rf "$BUILD"
mkdir -p "$BUILD" "$INCLUDE"

# Fast static check. Exported code also receives the cdylib call-graph safety
# validation here, so an unsupported construct fails before any artifact exists.
# shellcheck disable=SC2086
"$ELEPHC" --check $target_args "$SRC/workflow_native.php"

# Standalone executable: one JSON request on stdin, one response on stdout.
# shellcheck disable=SC2086
"$ELEPHC" --quiet --emit executable $target_args "$SRC/main.php"
mv "$SRC/main" "$BUILD/workflow-native"

# C-ABI shared library and its generated header.
# shellcheck disable=SC2086
"$ELEPHC" --quiet --emit cdylib $target_args "$SRC/workflow_native.php"
if [ -e "$SRC/libworkflow_native.h" ]; then
    mv "$SRC/libworkflow_native.h" "$INCLUDE/libworkflow_native.h"
fi
for candidate in "$SRC"/libworkflow_native.so "$SRC"/libworkflow_native.dylib "$SRC"/libworkflow_native.a; do
    if [ -e "$candidate" ]; then
        mv "$candidate" "$BUILD/"
    fi
done

echo "Artifacts:"
echo "  $BUILD/workflow-native"
ls "$BUILD" | grep -v '^workflow-native$' | sed "s|^|  $BUILD/|"
echo "  $INCLUDE/libworkflow_native.h"
