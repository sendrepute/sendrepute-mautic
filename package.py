#!/usr/bin/env python3
"""Build the installable Mautic plugin ZIP from an explicit allowlist."""

from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

ROOT = Path(__file__).resolve().parent
SOURCE = ROOT / "SendReputeBundle"
OUTPUT = ROOT / "dist" / "SendReputeBundle-0.1.0.zip"
ALLOWED_SUFFIXES = {".php", ".ini"}

files = sorted(
    path for path in SOURCE.rglob("*")
    if path.is_file() and path.suffix in ALLOWED_SUFFIXES and not path.is_symlink()
)
if not files:
    raise SystemExit("No plugin files found")

OUTPUT.parent.mkdir(parents=True, exist_ok=True)
with ZipFile(OUTPUT, "w", ZIP_DEFLATED) as archive:
    for path in files:
        archive.write(path, Path("SendReputeBundle") / path.relative_to(SOURCE))

print(OUTPUT)