"""Package the native SDK, not a self-contained operating-system runtime."""

import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import tarfile


architecture = {"x86_64": "amd64", "aarch64": "arm64"}[platform.machine()]
name = f"workflow-native-linux-{architecture}"
bundle = Path("/bundle") / name
dist = Path("/dist")
for directory in (bundle / "bin", bundle / "lib", bundle / "include", bundle / "examples", bundle / "licenses", dist):
    directory.mkdir(parents=True, exist_ok=True)

files = {
    "/build/workflow-native": "bin/workflow-native",
    "/build/libworkflow_native.so": "lib/libworkflow_native.so",
    "/opt/typephp/vendor/swoole/phpx/lib/libphpx.so": "lib/libphpx.so",
    "/workspace/mod/typephp/include/workflow_native.h": "include/workflow_native.h",
    "/workspace/mod/typephp/examples/node_client.mjs": "examples/node_client.mjs",
    "/workspace/mod/typephp/examples/python_client.py": "examples/python_client.py",
    "/workspace/mod/typephp/DISTRIBUTION.md": "README.md",
    "/workspace/LICENSE": "licenses/WorkFlow-MIT.txt",
    "/opt/typephp/vendor/swoole/phpx/LICENSE": "licenses/PHPX-Apache-2.0.txt",
    "/opt/typephp/vendor/swoole/typephp/LICENSE": "licenses/TypePHP-compiler-GPL-3.0.txt",
    "/workspace/mod/typephp/composer.lock": "toolchain-composer.lock",
}
for source, destination in files.items():
    shutil.copy2(source, bundle / destination)

with tarfile.open("/usr/src/php.tar.xz") as php_source:
    license_member = next(member for member in php_source.getmembers() if member.name.count("/") == 1 and member.name.endswith("/LICENSE"))
    with php_source.extractfile(license_member) as license_file:
        (bundle / "licenses/PHP-LICENSE.txt").write_bytes(license_file.read())

(bundle / "manifest.json").write_text(json.dumps({
    "format": 1,
    "platform": f"linux/{architecture}",
    "native_abi": 1,
    "native_protocol": 1,
    "php": os.environ["PHP_VERSION"],
    "typephp": "0.9.3",
    "phpx": "2.9.2",
    "runtime": "Matching WorkFlow native Docker image (Debian Bookworm/glibc). This SDK archive is not self-contained.",
    "sources": {
        "workflow": "https://github.com/webong/workflow",
        "typephp": "https://github.com/swoole/typephp/tree/v0.9.3",
        "phpx": "https://github.com/swoole/phpx/tree/v2.9.2",
        "php": f"https://www.php.net/distributions/php-{os.environ['PHP_VERSION']}.tar.xz",
    },
}, indent=2) + "\n")

archive = dist / f"{name}.tar.gz"
with tarfile.open(archive, "w:gz") as output:
    output.add(bundle, arcname=name)
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(dist / f"{archive.name}.sha256").write_text(f"{digest}  {archive.name}\n")
print(f"Packaged {archive.name}; runtime dependencies are supplied by the companion Docker image")
