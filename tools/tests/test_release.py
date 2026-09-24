import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("release", Path(__file__).parents[1] / "prepare-release.py")
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        for arch in ("amd64", "arm64"):
            name = f"workflow-native-linux-{arch}"
            archive = self.directory / f"{name}.tar.gz"
            with tarfile.open(archive, "w:gz") as sdk:
                files = {"manifest.json": json.dumps({"platform": f"linux/{arch}", "native_abi": 1}).encode()}
                for path in ("bin/workflow-native", "lib/libworkflow_native.so", "lib/libphpx.so", "include/workflow_native.h", "licenses/WorkFlow-MIT.txt", "licenses/PHPX-Apache-2.0.txt", "README.md"):
                    files[path] = b"fixture"
                for path, data in files.items():
                    info = tarfile.TarInfo(f"{name}/{path}")
                    info.size = len(data)
                    sdk.addfile(info, io.BytesIO(data))
            digest = hashlib.sha256(archive.read_bytes()).hexdigest()
            (self.directory / f"{archive.name}.sha256").write_text(f"{digest}  {archive.name}\n")
            for kind in ("native", "rpc"):
                (self.directory / f"workflow-{kind}-image-linux-{arch}.tar.gz").write_bytes(b"image fixture")

    def test_complete_release_has_checksums_for_all_six_assets(self):
        release.prepare(self.directory, "v0.1.0-rc.1")
        self.assertEqual(6, len((self.directory / "SHA256SUMS").read_text().splitlines()))

    def test_unsafe_or_non_version_tags_are_rejected(self):
        for tag in ("main", "v1", "v01.2.3", "v1.2.3;echo bad", "v1.2.3/other", ""):
            with self.subTest(tag=tag), self.assertRaises(ValueError):
                release.validate_tag(tag)
        release.validate_tag("v1.2.3")

    def test_missing_platform_is_rejected(self):
        (self.directory / "workflow-rpc-image-linux-arm64.tar.gz").unlink()
        with self.assertRaisesRegex(ValueError, "asset mismatch"):
            release.prepare(self.directory, "v1.2.3")

    def test_corrupted_sdk_is_rejected(self):
        with (self.directory / "workflow-native-linux-arm64.tar.gz").open("ab") as archive:
            archive.write(b"corruption")
        with self.assertRaisesRegex(ValueError, "checksum mismatch"):
            release.prepare(self.directory, "v1.2.3")

    def test_unexpected_asset_is_rejected(self):
        (self.directory / "extra.tar.gz").write_bytes(b"unexpected")
        with self.assertRaisesRegex(ValueError, "asset mismatch"):
            release.prepare(self.directory, "v1.2.3")

    def test_empty_image_is_rejected(self):
        (self.directory / "workflow-native-image-linux-amd64.tar.gz").write_bytes(b"")
        with self.assertRaisesRegex(ValueError, "Empty release asset"):
            release.prepare(self.directory, "v1.2.3")

    def test_mislabelled_sdk_platform_is_rejected(self):
        name = "workflow-native-linux-arm64"
        archive = self.directory / f"{name}.tar.gz"
        with tarfile.open(archive, "w:gz") as sdk:
            data = json.dumps({"platform": "linux/amd64", "native_abi": 1}).encode()
            info = tarfile.TarInfo(f"{name}/manifest.json")
            info.size = len(data)
            sdk.addfile(info, io.BytesIO(data))
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        (self.directory / f"{archive.name}.sha256").write_text(f"{digest}  {archive.name}\n")
        with self.assertRaisesRegex(ValueError, "platform/ABI mismatch"):
            release.prepare(self.directory, "v1.2.3")
