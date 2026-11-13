#!/usr/bin/env bash
# Real dependency installations; never project a developer checkout or symlink.
set -euo pipefail
cd "$(dirname "$0")/../.."
php tests/integration/composer-surfaces.php
