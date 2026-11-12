"""Package the Manticore native SDK bundle."""

import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import tarfile


architecture = {"x86_64": "amd64", "aarch64": "arm64"}[platform.machine()]
name = f"workflow-native-manticore-linux-{architecture}"
bundle = Path("/bundle") / name
dist = Path("/dist")
for directory in (bundle / "bin", bundle / "lib", bundle / "include", bundle / "examples", bundle / "licenses", dist):
    directory.mkdir(parents=True, exist_ok=True)

# Manticore emits a native executable with no PHP runtime, no PHPX.
# The binary links the host's libc, pcre2, openssl, and zlib.
files = {
    "/build/workflow-native": "bin/workflow-native",
    "/workspace/mod/manticore/examples/node_client.mjs": "examples/node_client.mjs",
    "/workspace/mod/manticore/examples/python_client.py": "examples/python_client.py",
    "/workspace/mod/manticore/DISTRIBUTION.md": "README.md",
    "/workspace/LICENSE": "licenses/WorkFlow-MIT.txt",
}
for source, destination in files.items():
    shutil.copy2(source, bundle / destination)

# Manticore is MIT licensed; no PHP/GPL notices needed for the runtime image.
# (Manticore itself, clang, and the system libraries are not bundled.)

(bundle / "manifest.json").write_text(json.dumps({
    "format": 1,
    "platform": f"linux/{architecture}",
    "native_abi": 0,
    "native_protocol": 1,
    "manticore": os.environ.get("MANTICORE_VERSION", "latest"),
    "runtime": "Manticore-native Docker image (Debian Bookworm/glibc). The binary links the host's libc, pcre2, openssl, and zlib.",
    "sources": {
        "workflow": "https://github.com/webong/workflow",
        "manticore": "https://github.com/manticorephp/compiler",
    },
}, indent=2) + "\n")

archive = dist / f"{name}.tar.gz"
with tarfile.open(archive, "w:gz") as output:
    output.add(bundle, arcname=name)
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(dist / f"{archive.name}.sha256").write_text(f"{digest}  {archive.name}\n")
print(f"Packaged {archive.name}; runtime dependencies (libc, pcre2, openssl, zlib) are supplied by the host")
