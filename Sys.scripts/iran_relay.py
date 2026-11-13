#!/usr/bin/env python3
"""Route Iranian sites through the holding's Iranian relay host instead of fetching directly.

Why: Iranian sites either refuse or answer differently from outside Iran, and the
private repo's Actions minutes are billed. Every crawler in this public repo asks
should_route(url) first; an Iranian URL is fetched by relay.hohe1.ir (inside Iran),
everything else is fetched here as before.

Auth: the relay accepts sig = HMAC-SHA256(key, "ts|job|q"). This repo only holds the
separate read-only run key (secret IRAN_RELAY_RUN_KEY = 'run_key' in the host's
config.php), never the master secret that can write files on the host. No key => an
Iranian URL fails loudly instead of being fetched from outside Iran.

CLI (for quick checks):  python3 iran_relay.py job dk-search "<query>"
"""
import base64
import hashlib
import hmac
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from urllib.parse import urlparse

RELAY = os.environ.get("IRAN_RELAY_URL", "https://relay.hohe1.ir/run.php")
# Kept in step with $IRANIAN_COM in the relay's run.php (Agent-Project-Backup,
# Memory-Workflow/iran-relay-host/site/relay/public_html/run.php).
IRANIAN_COM = ("digikala.com", "torob.com", "aparat.com", "behtarino.com", "shahrint.com",
               "royasho.com", "digistyle.com", "basalam.com", "sheypoor.com", "filimo.com",
               # 1405-08-22: named Iranian .org sites (the .org suffix alone is not a signal)
               "sanjesh.org", "azmoon.org", "iranbar.org")


def is_iranian(url):
    host = (urlparse(url).hostname or "").lower()
    if not host:
        return False
    if host.endswith(".ir"):
        return True
    return any(host == d or host.endswith("." + d) for d in IRANIAN_COM)


def key():
    return (os.environ.get("IRAN_RELAY_RUN_KEY") or "").strip()


def should_route(url):
    return is_iranian(url)


class RelayError(RuntimeError):
    pass


def job(name, q="", timeout=60, data=None):
    k = key()
    if not k:
        raise RelayError("IRAN_RELAY_RUN_KEY is not set - Iranian sites must go through the relay")
    ts = str(int(time.time()))
    sig = hmac.new(k.encode(), f"{ts}|{name}|{q}".encode(), hashlib.sha256).hexdigest()
    url = RELAY + "?" + urllib.parse.urlencode({"ts": ts, "job": name, "q": q, "sig": sig})
    req = urllib.request.Request(url, data=data, headers={"User-Agent": "iran-relay-client",
                                                          "Content-Type": "application/octet-stream"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            body = r.read()
    except urllib.error.HTTPError as e:
        body = e.read()
    try:
        return json.loads(body)
    except ValueError:
        raise RelayError("relay answered non-JSON: " + body[:200].decode("utf-8", "replace"))


def fetch(url, timeout=60, max_bytes=None):
    """Same return shape as web_extract.fetch: (status, headers, raw, truncated, size)."""
    d = job("fetch", url, timeout)
    if d.get("error"):
        raise RelayError(f"relay refused {url}: {d.get('error')}")
    raw = (d.get("body") or "").encode("utf-8", "surrogatepass")
    trunc = bool(d.get("truncated"))
    if max_bytes is not None and len(raw) > max_bytes:
        raw, trunc = raw[:max_bytes], True
    headers = {"Content-Type": d.get("ctype") or "text/html; charset=utf-8", "X-Via": "iran-relay"}
    status = int(d.get("code") or 0)
    if status >= 400 or status == 0:
        raise urllib.error.HTTPError(url, status or 502, d.get("err") or "relay fetch failed", headers, None)
    return status, headers, raw, trunc, len(raw)


# Header names the relay forwards upstream (run.php keeps the same allow-list).
RAW_HEADERS = ("accept", "accept-language", "content-type", "referer", "origin", "cookie",
               "x-requested-with", "authorization", "user-agent")


def raw(url, method="GET", body=b"", headers=None, timeout=150, chunk=2_000_000, max_bytes=80_000_000):
    """Byte-exact HTTP request made from inside Iran (relay job 'raw', 1405-08-22).

    Returns {"status", "ctype", "final", "body" (bytes), "headers" (set-cookie/location/...)}.
    Unlike fetch(), binary bodies (PDF, images, JS bundles) survive intact and large files
    arrive in chunks. A real browser routes every Iranian request through this, so
    client-rendered Iranian pages render with an Iranian IP.
    """
    body = body or b""
    hd = {k.lower(): v for k, v in (headers or {}).items() if k.lower() in RAW_HEADERS}
    out, off, first = bytearray(), 0, None
    while True:
        q = json.dumps({"u": url, "m": method.upper(), "o": off, "n": chunk,
                        "h": hashlib.sha256(body).hexdigest(), "hd": hd},
                       ensure_ascii=False, separators=(",", ":"), sort_keys=True)
        d = job("raw", q, timeout, data=body if method.upper() == "POST" else None)
        if d.get("error"):
            raise RelayError(f"relay refused {url}: {d.get('error')}")
        first = first or d
        out += base64.b64decode(d.get("b64") or "")
        off = len(out)
        if d.get("done") or not d.get("len") or off >= max_bytes:
            break
    return {"status": int(first.get("code") or 0), "ctype": first.get("ctype") or "",
            "final": first.get("final") or url, "headers": first.get("headers") or {},
            "total": int(first.get("total") or 0), "body": bytes(out), "err": first.get("err") or ""}


if __name__ == "__main__":
    if len(sys.argv) >= 3 and sys.argv[1] == "job":
        print(json.dumps(job(sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else ""), ensure_ascii=False)[:3000])
    else:
        print(__doc__)
