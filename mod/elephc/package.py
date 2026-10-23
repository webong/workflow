"""Package the elephc native SDK bundle."""

import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import tarfile


architecture = {"x86_64": "amd64", "aarch64": "arm64"}[platform.machine()]
name = f"workflow-native-elephc-linux-{architecture}"
bundle = Path("/bundle") / name
dist = Path("/dist")
for directory in (bundle / "bin", bundle / "lib", bundle / "include", bundle / "examples", bundle / "licenses", dist):
    directory.mkdir(parents=True, exist_ok=True)

# elephc artifacts are self-contained (no PHP runtime, no PHPX)
# The bridge staticlibs are linked into the binary/dylib
files = {
    "/build/workflow-native": "bin/workflow-native",
    "/build/libworkflow_native.so": "lib/libworkflow_native.so",
    "/workspace/mod/elephc/include/libworkflow_native.h": "include/libworkflow_native.h",
    "/workspace/mod/elephc/examples/node_client.mjs": "examples/node_client.mjs",
    "/workspace/mod/elephc/examples/python_client.py": "examples/python_client.py",
    "/workspace/mod/elephc/examples/c_host.c": "examples/c_host.c",
    "/workspace/mod/elephc/DISTRIBUTION.md": "README.md",
    "/workspace/LICENSE": "licenses/WorkFlow-MIT.txt",
    "/workspace/mod/elephc/elephc.lock": "toolchain-elephc.lock",
}
for source, destination in files.items():
    if Path(source).exists():
        shutil.copy2(source, bundle / destination)

# elephc is MIT licensed, no PHP/GPL notices needed
# (elephc binary and bridge staticlibs are MIT)

(bundle / "manifest.json").write_text(json.dumps({
    "format": 1,
    "platform": f"linux/{architecture}",
    "native_abi": 1,
    "native_protocol": 1,
    "elephc": os.environ.get("ELEPHC_VERSION", "0.27.5"),
    "runtime": "Matching elephc native Docker image (Debian Bookworm/glibc). SDK archive is self-contained for this core.",
    "sources": {
        "workflow": "https://github.com/webong/workflow",
        "elephc": f"https://github.com/illegalstudio/elephc/releases/tag/v{os.environ.get('ELEPHC_VERSION', '0.27.5')}",
    },
}, indent=2) + "\n")

archive = dist / f"{name}.tar.gz"
with tarfile.open(archive, "w:gz") as output:
    output.add(bundle, arcname=name)
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(dist / f"{archive.name}.sha256").write_text(f"{digest}  {archive.name}\n")
print(f"Packaged {archive.name}; runtime dependencies are supplied by the companion Docker image")