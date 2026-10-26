#!/usr/bin/env python3
"""Refusal controls for published fixture admission, without network access."""

import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
import zipfile

sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location(
    "prepare_example", Path(__file__).resolve().parent.parent / "scripts/prepare-github-example-fixture.py"
)
PREPARE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PREPARE)


class AdmissionTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        (self.root / "tests/fixtures").mkdir(parents=True)
        (self.root / "vendor/.test-fixtures").mkdir(parents=True)
        self.archive = self.root / "vendor/.test-fixtures/ran-booster-github-example-0.2.0.zip"
        self.packages = [
            {"name": name, "source": {"reference": "a" * 40}}
            for name in ("ran/booster-github-provider", "ran/updater-support")
        ]
        (self.root / "composer.lock").write_text(json.dumps({"packages": self.packages}))

    def fixture(self, entries=None):
        with zipfile.ZipFile(self.archive, "w") as bundle:
            bundle.writestr("ran-booster-github-example/vendor/composer/installed.json",
                            json.dumps({"packages": self.packages}))
            for path, value in entries or []:
                bundle.writestr(path, value)
        self.pin = {"version": "0.2.0", "sha256": hashlib.sha256(self.archive.read_bytes()).hexdigest()}
        (self.root / "tests/fixtures/github-example.json").write_text(json.dumps(self.pin))

    def test_verified_fixture_replaces_modified_extraction_with_published_bytes(self):
        self.fixture([("ran-booster-github-example/plugin.php", "published")])
        PREPARE.prepare(self.root)
        plugin = self.root / "vendor/.test-fixtures/ran-booster-github-example/plugin.php"
        plugin.write_text("locally modified")
        PREPARE.prepare(self.root)
        self.assertEqual("published", plugin.read_text())

    def test_corrupt_cached_archive_refuses_before_extraction(self):
        self.fixture()
        self.archive.write_bytes(self.archive.read_bytes() + b"changed")
        with self.assertRaisesRegex(ValueError, "checksum mismatch"):
            PREPARE.prepare(self.root)

    def test_different_core_package_refuses(self):
        self.fixture()
        self.packages[0]["source"]["reference"] = "b" * 40
        (self.root / "composer.lock").write_text(json.dumps({"packages": self.packages}))
        with self.assertRaisesRegex(ValueError, "package mismatch"):
            PREPARE.prepare(self.root)

    def test_paths_outside_plugin_refuse(self):
        for path in ("../escape.php", "/escape.php", "other/plugin.php", "ran-booster-github-example/../escape.php"):
            with self.subTest(path=path):
                self.fixture([(path, "unexpected")])
                with self.assertRaisesRegex(ValueError, "Unsafe"):
                    PREPARE.prepare(self.root)

    def test_symlink_entry_refuses(self):
        entry = zipfile.ZipInfo("ran-booster-github-example/link.php")
        entry.create_system = 3
        entry.external_attr = 0o120777 << 16
        self.fixture([(entry, "../../escape.php")])
        with self.assertRaisesRegex(ValueError, "Unsafe"):
            PREPARE.prepare(self.root)

    def test_symlink_destination_refuses(self):
        self.fixture()
        target = self.root / "untouched"
        target.mkdir()
        (self.root / "vendor/.test-fixtures/ran-booster-github-example").symlink_to(target, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "destination must not be a symlink"):
            PREPARE.prepare(self.root)
        self.assertTrue(target.is_dir())

    def test_symlink_parents_refuse_without_touching_redirected_files(self):
        for parent in ("vendor/.test-fixtures", "vendor"):
            with self.subTest(parent=parent):
                self.fixture()
                directory = self.root / parent
                redirected = self.root / ("redirected-" + directory.name)
                directory.rename(redirected)
                directory.symlink_to(redirected, target_is_directory=True)
                marker = redirected / "retained.txt"
                marker.write_text("untouched")
                try:
                    with self.assertRaisesRegex(ValueError, "parents must be canonical"):
                        PREPARE.prepare(self.root)
                    self.assertEqual("untouched", marker.read_text())
                finally:
                    directory.unlink()
                    redirected.rename(directory)


if __name__ == "__main__":
    unittest.main()
