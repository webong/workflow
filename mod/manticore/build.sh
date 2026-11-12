#!/bin/sh
# Build the experimental Manticore artifacts. Run from the repository root.
#
#   sh mod/manticore/build.sh            # host target (outputs to mod/manticore/build)
#
# In Docker (compose), the working directory is /workspace and /build is a volume.
# Set MANTICORE to use a compiler other than the one on PATH.
set -eu

MANTICORE="${MANTICORE:-manticore}"
SRC="mod/manticore/php"

# In Docker, /build is a writable volume; on host, use mod/manticore/build
if [ -w /build ] && [ "$(pwd)" = "/workspace" ]; then
    BUILD="/build"
else
    BUILD="mod/manticore/build"
fi

command -v "$MANTICORE" >/dev/null 2>&1 || {
    echo "manticore not found. Install it first: https://github.com/manticorephp/compiler" >&2
    echo "  curl -fsSL https://raw.githubusercontent.com/manticorephp/compiler/main/install.sh | bash" >&2
    exit 1
}

[ -f composer.json ] || {
    echo "Run this script from the repository root so Manticore can read the source layout." >&2
    exit 1
}

# Preserve the PHP oracle and other volume contents. Remove only our outputs.
mkdir -p "$BUILD"
rm -f "$BUILD/workflow-native"

# Front-end check: parse and lower the whole entry (plus all required includes)
# without emitting a binary. Reports every front-end error in one pass.
"$MANTICORE" compile "$SRC/entry.php" --check

# Standalone executable: one JSON request on stdin, one JSON response on stdout.
# -O2 is the default; --memory=hybrid routes allocations between arena and heap-rc.
"$MANTICORE" compile "$SRC/entry.php" -o "$BUILD/workflow-native" -O2 --memory=hybrid

echo "Artifacts:"
ls -la "$BUILD/workflow-native"
file "$BUILD/workflow-native" 2>/dev/null || true
