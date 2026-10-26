#!/usr/bin/env python3
"""Prepare the pinned published consumer; never assemble a substitute plugin."""

import hashlib
import json
from pathlib import Path, PurePosixPath
import shutil
import stat
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
PLUGIN = "ran-booster-github-example"


def prepare(root=ROOT):
    root = root.resolve(strict=True)
    pin = json.loads((root / "tests/fixtures/github-example.json").read_text())
    fixtures = root / "vendor/.test-fixtures"
    # Do not follow a redirected dependency/cache parent before writing or
    # replacing generated files. The destination check alone is insufficient.
    for directory in (root / "vendor", fixtures):
        if directory.is_symlink() or directory.resolve() != directory:
            raise ValueError("Fixture parents must be canonical non-symlinked directories.")
    fixtures.mkdir(parents=True, exist_ok=True)
    archive = fixtures / f"{PLUGIN}-{pin['version']}.zip"
    if archive.is_symlink():
        raise ValueError("Fixture archive must not be a symlink.")
    if not archive.exists():
        url = (f"https://github.com/{pin['repository']}/releases/download/"
               f"v{pin['version']}/{PLUGIN}-{pin['version']}.zip")
        with tempfile.TemporaryDirectory(dir=fixtures) as temporary:
            download = Path(temporary) / "download.zip"
            subprocess.run(["curl", "--fail", "--location", "--silent", "--show-error",
                            "--connect-timeout", "15", "--max-time", "120",
                            url, "--output", str(download)], check=True)
            if hashlib.sha256(download.read_bytes()).hexdigest() != pin["sha256"]:
                raise ValueError("Published Example fixture checksum mismatch.")
            download.replace(archive)
    if hashlib.sha256(archive.read_bytes()).hexdigest() != pin["sha256"]:
        raise ValueError("Cached Example fixture checksum mismatch; remove the corrupt ZIP.")
    destination = fixtures / PLUGIN
    if destination.is_symlink():
        raise ValueError("Fixture destination must not be a symlink.")
    with tempfile.TemporaryDirectory(dir=fixtures) as temporary:
        staging = Path(temporary)
        with zipfile.ZipFile(archive) as bundle:
            names = bundle.namelist()
            if len(names) != len(set(names)):
                raise ValueError("Duplicate Example archive entries.")
            for entry in bundle.infolist():
                path = PurePosixPath(entry.filename)
                if (path.is_absolute() or ".." in path.parts or not path.parts
                        or path.parts[0] != PLUGIN or "\\" in entry.filename
                        or stat.S_ISLNK(entry.external_attr >> 16)):
                    raise ValueError("Unsafe Example archive entry.")
            bundle.extractall(staging)
        installed = json.loads((staging / PLUGIN / "vendor/composer/installed.json").read_text())
        packages = {p["name"]: p for p in installed["packages"]}
        locked = {p["name"]: p for p in json.loads((root / "composer.lock").read_text())["packages"]}
        # Provider and Support share PHP namespaces with Core. Updater owns its
        # independent protocol-qualified bootstrap and may carry a different release.
        for name in ("ran/booster-github-provider", "ran/updater-support"):
            if packages[name]["source"]["reference"] != locked[name]["source"]["reference"]:
                raise ValueError(f"Example/Core package mismatch: {name}; qualify a new fixture pin.")
        if destination.exists():
            shutil.rmtree(destination)
        (staging / PLUGIN).replace(destination)
    print(f"Prepared published GitHub Example {pin['version']} ({pin['sha256']}).")


if __name__ == "__main__":
    prepare()
