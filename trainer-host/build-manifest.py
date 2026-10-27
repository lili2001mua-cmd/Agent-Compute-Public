#!/usr/bin/env python3
"""Rebuild trainer-host/manifest.json from trainer-host/files/ (sha256 per file).

Run after any change under files/:  python3 trainer-host/build-manifest.py
The host's deploy.php pulls only what this manifest lists.
"""
import hashlib, json, os, sys
from datetime import datetime, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
FILES = os.path.join(HERE, "files")
OUT = os.path.join(HERE, "manifest.json")

def main():
    old = {}
    if os.path.exists(OUT):
        old = json.load(open(OUT, encoding="utf-8")).get("files", {})
    files = {}
    for root, _, names in os.walk(FILES):
        for n in sorted(names):
            p = os.path.join(root, n)
            rel = os.path.relpath(p, FILES).replace(os.sep, "/")
            files[rel] = hashlib.sha256(open(p, "rb").read()).hexdigest()
    for rel, sha in old.items():
        if rel not in files and sha is not None:
            files[rel] = None  # tells the host to delete it
    version = datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")
    json.dump({"version": version, "files": dict(sorted(files.items()))},
              open(OUT, "w", encoding="utf-8"), indent=2)
    print(f"manifest {version}: {sum(1 for v in files.values() if v)} files")

if __name__ == "__main__":
    sys.exit(main())
