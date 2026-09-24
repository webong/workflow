"""Reject incomplete/mislabelled distributions before publishing anything."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import tarfile


def validate_tag(tag):
    if not re.fullmatch(r"v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?", tag):
        raise ValueError("Use a version tag such as v0.1.0 or v0.1.0-rc.1")


def prepare(directory, tag):
    validate_tag(tag)
    directory = Path(directory)
    expected = set()
    for arch in ("amd64", "arm64"):
        name = f"workflow-native-linux-{arch}"
        archive = directory / f"{name}.tar.gz"
        expected.update((archive.name, f"workflow-native-image-linux-{arch}.tar.gz", f"workflow-rpc-image-linux-{arch}.tar.gz"))
        checksum = (directory / f"{archive.name}.sha256").read_text().split()
        if checksum != [hashlib.sha256(archive.read_bytes()).hexdigest(), archive.name]:
            raise ValueError(f"SDK checksum mismatch: {arch}")
        with tarfile.open(archive) as sdk:
            with sdk.extractfile(f"{name}/manifest.json") as manifest_file:
                manifest = json.load(manifest_file)
            if manifest["platform"] != f"linux/{arch}" or manifest["native_abi"] != 1:
                raise ValueError(f"SDK platform/ABI mismatch: {arch}")
            for required in ("bin/workflow-native", "lib/libworkflow_native.so", "lib/libphpx.so", "include/workflow_native.h", "licenses/WorkFlow-MIT.txt", "licenses/PHPX-Apache-2.0.txt", "README.md"):
                if not sdk.getmember(f"{name}/{required}").isfile():
                    raise ValueError(f"SDK member is not a file: {required}")
    actual = {path.name for path in directory.glob("*.tar.gz")}
    if actual != expected:
        raise ValueError(f"Release asset mismatch: missing={expected - actual}, unexpected={actual - expected}")
    sums = []
    for name in sorted(expected):
        path = directory / name
        if path.stat().st_size == 0:
            raise ValueError(f"Empty release asset: {name}")
        with path.open("rb") as source:
            digest = hashlib.file_digest(source, "sha256").hexdigest()
        sums.append(f"{digest}  {name}\n")
    (directory / "SHA256SUMS").write_text("".join(sums))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--validate-tag")
    parser.add_argument("--directory", default="dist/release")
    parser.add_argument("--tag")
    args = parser.parse_args()
    if args.validate_tag:
        validate_tag(args.validate_tag)
    elif args.tag:
        prepare(args.directory, args.tag)
    else:
        parser.error("Provide --validate-tag or --tag")
