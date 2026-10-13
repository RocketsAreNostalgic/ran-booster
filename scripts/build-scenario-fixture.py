#!/usr/bin/env python3
"""Build a deterministic, separate test plugin; never a Core release asset."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import zipfile


def target_archive(source: Path, slug: str, output: Path, seed: bool = False) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted((source / "samples" / slug).rglob("*")):
            if path.is_symlink():
                raise ValueError("Symlink in sample target")
            if not path.is_file():
                continue
            data = path.read_bytes()
            if seed:
                data = data.replace(b"Version: 2.0.0", b"Version: 1.0.0")
            name = slug + "/" + path.relative_to(source / "samples" / slug).as_posix()
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, data, compresslevel=9)


def build(source: Path, destination: Path) -> Path:
    if source.is_symlink() or any(path.is_symlink() for path in source.rglob("*")):
        raise ValueError("Symlinks are not fixture package inputs")
    manifest = json.loads((source / "manifest.json").read_text())
    version = manifest["fixture_version"]
    core = manifest["core_version"]
    if not re.fullmatch(r"\d+\.\d+\.\d+", version):
        raise ValueError("Invalid fixture version")
    if not re.fullmatch(r"\d+\.\d+\.\d+(?:-[a-z]+\.\d+)?", core):
        raise ValueError("Invalid supported Core version")
    if not re.fullmatch(r"[a-f0-9]{40}", manifest["core_commit"]):
        raise ValueError("An exact released Core commit is required")
    header = (source / "ran-booster-scenarios.php").read_text()
    if not re.search(r"^\s*\* Version:\s*" + re.escape(version) + r"\s*$", header, re.M):
        raise ValueError("Plugin header and fixture manifest versions differ")
    for slug in ("scenario-plugin", "scenario-theme"):
        target_archive(source, slug, source / "archives" / f"{slug}.zip")
        seed = destination / f"{slug}-1.0.0.zip"
        target_archive(source, slug, seed, seed=True)
        seed.with_suffix(".zip.sha256").write_text(f"{hashlib.sha256(seed.read_bytes()).hexdigest()}  {seed.name}\n")
    files = sorted(path for path in source.rglob("*") if path.is_file())
    allowed = {".php", ".json", ".md", ".css", ".txt", ".zip"}
    for path in source.rglob("*"):
        if path.is_symlink():
            raise ValueError("Symlinks are not fixture package inputs")
        if path.is_file() and (path.suffix not in allowed or any(p.startswith(".") for p in path.relative_to(source).parts)):
            raise ValueError(f"Unexpected fixture input: {path.name}")
    destination.mkdir(parents=True, exist_ok=True)
    output = destination / f"ran-booster-scenarios-{version}-core-{core}.zip"
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in files:
            name = "ran-booster-scenarios/" + path.relative_to(source).as_posix()
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes(), compresslevel=9)
    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix(".zip.sha256").write_text(f"{digest}  {output.name}\n")
    return output


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=Path("build/scenario-fixture"))
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    print(build(root / "tests/fixtures/ran-booster-scenarios", args.output))
