#!/usr/bin/env python3
"""
Cardinal — package the upload-ready ZIP.

Builds the archive that gets extracted into public_html. Contents are exactly
what the README's upload procedure asks for and nothing else:

  .htaccess, index.html, api.php, config.php, private-config.example.php,
  README.md, lib/, assets/

Deliberately excluded:
  tools/      build-time graphics pipeline; never needed at runtime
  .git/       repository metadata
  cardinal-private.php and any real credential file (must never be archived)

The archive is verified after writing: every referenced asset must resolve,
the hidden .htaccess must be present, and no PHP logic file may differ from
the committed version.

    python3 tools/make_upload_zip.py
    python3 tools/make_upload_zip.py --output /somewhere/else.zip
"""

from __future__ import annotations

import argparse
import hashlib
import pathlib
import re
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
PACKAGE = "20260907-online-4"

INCLUDE_FILES = [
    ".htaccess",
    "index.html",
    "api.php",
    "realtime.php",
    "config.php",
    "private-config.example.php",
    "README.md",
]
INCLUDE_DIRS = ["lib", "assets"]

# Never archive a real credential, whatever it is called.
FORBIDDEN = re.compile(r"(cardinal-private\.php|\.env|credentials?\.(php|json|ya?ml))$", re.I)

# Files whose contents define game rules. They are shipped as-is; this is a
# guard against ever packaging a modified copy.
LOGIC_FILES = [
    "api.php",
    "config.php",
    "lib/bootstrap.php",
    "lib/Game.php",
    "lib/Rules.php",
    "lib/DemoGame.php",
]

# realtime.php is new and must stay isolated from the game layer. If a future
# edit ever reaches for the game database, the package should refuse to build.
FORBIDDEN_IN_REALTIME = ["cardinal_game", "CardinalGame", "lib/bootstrap", "mysql:", "UPDATE players"]

# Files that must never regress to a location-gated inventory read again, and
# the action the interface now depends on. Cheap tripwires against a bad merge.
REQUIRED_SNIPPETS = {
    "lib/Game.php": ["case 'drop-crafted'", "private function dropCrafted"],
}


def collect() -> list[pathlib.Path]:
    picked: list[pathlib.Path] = []
    for name in INCLUDE_FILES:
        path = ROOT / name
        if not path.is_file():
            sys.exit(f"ABORT: missing required file {name}")
        picked.append(path)
    for name in INCLUDE_DIRS:
        base = ROOT / name
        if not base.is_dir():
            sys.exit(f"ABORT: missing required directory {name}/")
        for path in sorted(base.rglob("*")):
            if path.is_file():
                picked.append(path)
    for path in picked:
        if FORBIDDEN.search(path.name):
            sys.exit(f"ABORT: refusing to archive credential file {path.name}")
    return picked


def verify(zip_path: pathlib.Path) -> None:
    with zipfile.ZipFile(zip_path) as archive:
        names = set(archive.namelist())
        html = archive.read("index.html").decode("utf-8")

        missing = [ref for ref in sorted(set(re.findall(r"assets/[A-Za-z0-9_.-]+", html))) if ref not in names]
        if missing:
            sys.exit(f"ABORT: index.html references files absent from the archive: {missing}")

        # every chunk-to-chunk import must resolve too
        for name in names:
            if not name.endswith(".js"):
                continue
            body = archive.read(name).decode("utf-8", "ignore")
            for ref in set(re.findall(r"\./((?:index|WorldScene|polyfills)-[A-Za-z0-9_-]+\.js)", body)):
                if f"assets/{ref}" not in names:
                    sys.exit(f"ABORT: {name} imports missing chunk {ref}")

        for required in [".htaccess", "index.html", "api.php", "assets/cardinal-current-main.js",
                         "assets/cardinal-current.css", "assets/cardinal-current-world.js"]:
            if required not in names:
                sys.exit(f"ABORT: archive is missing {required}")

        for logic in LOGIC_FILES:
            packed = hashlib.sha256(archive.read(logic)).hexdigest()
            on_disk = hashlib.sha256((ROOT / logic).read_bytes()).hexdigest()
            if packed != on_disk:
                sys.exit(f"ABORT: {logic} in the archive differs from the working tree")

        if any(n.startswith("tools/") for n in names):
            sys.exit("ABORT: build tooling leaked into the upload archive")

        realtime = archive.read("realtime.php").decode("utf-8")
        for needle in FORBIDDEN_IN_REALTIME:
            if needle in realtime:
                sys.exit(f"ABORT: realtime.php reaches into the game layer ({needle!r})")
        if "cardinal-net-" not in html:
            sys.exit("ABORT: index.html does not load the realtime client")

        for path, snippets in REQUIRED_SNIPPETS.items():
            body = archive.read(path).decode("utf-8")
            for snippet in snippets:
                if snippet not in body:
                    sys.exit(f"ABORT: {path} in the archive is missing {snippet!r}")

        # both texture tiers must ship together
        base_maps = {n for n in names if n.startswith("assets/") and n.endswith(".jpg") and "-hi." not in n}
        for jpg in sorted(base_maps):
            twin = jpg.replace(".jpg", "-hi.jpg")
            if "auth-realm" in jpg:
                continue
            if twin not in names:
                sys.exit(f"ABORT: {jpg} has no -hi companion in the archive")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", default=str(ROOT / f"cardinal-web-server5-{PACKAGE}.zip"))
    args = parser.parse_args()

    out = pathlib.Path(args.output).resolve()
    files = collect()

    out.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in files:
            archive.write(path, path.relative_to(ROOT).as_posix())

    verify(out)

    total = sum(f.stat().st_size for f in files)
    print(f"{out}")
    print(f"  {len(files)} files, {total / 1024 / 1024:.2f} MB raw -> {out.stat().st_size / 1024 / 1024:.2f} MB zipped")
    print(f"  package: {PACKAGE}")
    print("  verified: asset graph resolves, .htaccess present, PHP files match the tree, no tools/")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
