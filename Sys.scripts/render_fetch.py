#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""render_fetch.py - render a JavaScript page in a real browser and read its text.

Some pages build their content client-side. A raw-HTML fetcher sees an empty shell:
HTTP 200, a few kilobytes of scaffolding, no readable content. This script opens the
same URL in Chromium, waits for the page to settle, and takes the text a human would
actually see.

Requires Playwright and a Chromium build. It is meant to run in CI, where a browser
can reach the network freely; sandboxes that proxy all egress often block the browser
even when plain HTTP clients work.

Rendering alone is not success. If the extracted text is still below --min-chars the
page is reported as a shell and the exit code is non-zero, so a caller cannot mistake
"the browser opened" for "we got the content".

For infinite-scroll listings, raise --scroll; each pass gives the page time to load
the next batch.

Usage:
  python3 render_fetch.py <url> [--out F] [--wait networkidle|load]
        [--scroll N] [--timeout MS] [--exe PATH] [--shot PNG] [--min-chars N]
Exit: 0 real text - 1 unreachable or still a shell - 2 bad input.
"""
import argparse
import json
import os
import sys
from urllib.parse import urlparse

UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36")


def find_exe(given):
    if given:
        return given
    base = os.environ.get("PLAYWRIGHT_BROWSERS_PATH", "")
    if base and os.path.isdir(base):
        for d in sorted(os.listdir(base), reverse=True):
            for sub in ("chrome-linux", "chrome-linux64"):    # newer Chromium builds ship chrome-linux64
                cand = os.path.join(base, d, sub, "chrome")
                if os.path.exists(cand):
                    return cand
    return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("url")
    ap.add_argument("--out", default="")
    ap.add_argument("--wait", default="networkidle", choices=("load", "domcontentloaded", "networkidle"))
    ap.add_argument("--scroll", type=int, default=0,
                    help="scroll to the bottom this many times (for infinite lists)")
    ap.add_argument("--timeout", type=int, default=60000)
    ap.add_argument("--exe", default="")
    ap.add_argument("--shot", default="", help="write a full-page screenshot here")
    ap.add_argument("--min-chars", type=int, default=500)
    a = ap.parse_args()

    if urlparse(a.url).scheme not in ("http", "https"):
        print("::error::URL must start with http or https.", file=sys.stderr)
        return 2
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import iran_relay
    iran = iran_relay.should_route(a.url)
    if iran:
        # 1405-08-22: an Iranian file (PDF ...) is read byte-exact through the relay's 'raw' job.
        try:
            first = iran_relay.raw(a.url, "GET", b"", {"accept": "*/*", "accept-language": "fa-IR,fa;q=0.9"})
        except Exception as e:                                # noqa: BLE001
            if "unknown-job" in str(e):                       # relay not yet upgraded: raw HTML as before
                return relay_raw_doc(a)
            print(f"::error::{a.url} via iran-relay - {str(e)[:160]}", file=sys.stderr)
            return 1
        if "pdf" in first["ctype"].lower() or first["body"][:5] == b"%PDF-":
            return pdf_doc(a, first)

    try:
        from playwright.sync_api import sync_playwright
    except ImportError:
        if iran:
            return relay_raw_doc(a)
        print("::error::playwright is not installed; this script is meant to run in CI.",
              file=sys.stderr)
        return 2

    exe = find_exe(a.exe)
    proxy = os.environ.get("HTTPS_PROXY") or os.environ.get("https_proxy")
    launch = {"args": ["--no-sandbox", "--disable-dev-shm-usage"]}
    if exe:
        launch["executable_path"] = exe
    if proxy:
        # Pass the proxy through when one is configured; report failures honestly.
        launch["proxy"] = {"server": proxy}
    timeout = max(a.timeout, 180000) if iran else a.timeout
    stats = {"relayed": 0, "relay_failed": 0, "blocked_assets": 0}

    with sync_playwright() as p:
        b = p.chromium.launch(**launch)
        ctx = b.new_context(user_agent=UA, locale="fa-IR",
                            viewport={"width": 1440, "height": 900})
        if iran:
            # Every request to an Iranian host leaves from inside Iran (relay 'raw'); the rest go direct.
            ctx.route("**/*", lambda route, request: relay_route(route, request, ctx, a, stats, iran_relay))
        pg = ctx.new_page()
        try:
            pg.goto(a.url, wait_until=a.wait, timeout=timeout)
        except Exception as e:                                # noqa: BLE001
            print(f"::error::{a.url} did not load - {str(e).splitlines()[0][:120]}", file=sys.stderr)
            b.close()
            return 1

        if iran:
            # Anti-bot interstitials ("Transferring to the website...") set a cookie and reload; give them time.
            for _ in range(4):
                if len(pg.inner_text("body").strip()) >= a.min_chars:
                    break
                pg.wait_for_timeout(6000)
                try:
                    pg.wait_for_load_state("networkidle", timeout=30000)
                except Exception:                             # noqa: BLE001
                    pass
            if a.out:
                open(os.path.splitext(a.out)[0] + ".html", "w", encoding="utf-8").write(pg.content())
        for _ in range(a.scroll):
            pg.mouse.wheel(0, 20000)
            pg.wait_for_timeout(1200)                         # give the page time to load the next batch

        text = pg.inner_text("body")
        links = sorted(set(pg.eval_on_selector_all("a[href]", "e=>e.map(x=>x.href)")))
        links = [l for l in links if l.startswith(("http://", "https://"))]
        title = pg.title()
        if a.shot:
            pg.screenshot(path=a.shot, full_page=True)
        b.close()

    doc = {"url": a.url, "title": title, "text": text,
           "links": links[:400], **({"via": "browser+iran-relay", "relay": stats} if iran else {}),
           "stats": {"text_chars": len(text), "links_total": len(links),
                     "links_shown": min(len(links), 400),
                     "scrolled": a.scroll, "wait": a.wait,
                     "shell": len(text) < a.min_chars}}
    if len(links) > 400:
        doc["stats"]["links_note"] = f"showing the first 400 of {len(links)} links."

    out = json.dumps(doc, ensure_ascii=False, indent=1)
    if a.out:
        open(a.out, "w", encoding="utf-8").write(out)
        print(f"✓ {a.url} → {a.out} · {len(text)} chars - {len(links)} links"
              + (" - still a shell" if doc["stats"]["shell"] else ""))
    else:
        print(out)
    # Rendering alone is not success - real text must have come out.
    return 1 if doc["stats"]["shell"] else 0


def relay_route(route, request, ctx, a, stats, iran_relay):
    """Answer one browser request for an Iranian host from the relay; let the rest through."""
    u = request.url
    if not iran_relay.should_route(u):
        return route.continue_()
    if request.resource_type in ("image", "media", "font") and not a.shot:
        stats["blocked_assets"] += 1
        return route.abort()
    m = request.method if request.method in ("GET", "POST", "HEAD") else "GET"
    try:
        x = iran_relay.raw(u, m, request.post_data_buffer or b"", request.all_headers())
    except Exception:                                         # noqa: BLE001
        stats["relay_failed"] += 1
        return route.abort()
    stats["relayed"] += 1
    stats.setdefault("log", []).append([m, u[:200], x["status"], (x["ctype"] or "")[:40], len(x["body"]),
                                        x["final"][:200] if x["final"] != u else ""])
    body = x["body"]
    if x["final"] and x["final"] != u and "html" in (x["ctype"] or "").lower():
        # The relay already followed the redirect. Never hand the browser a 3xx: Chromium follows it itself,
        # outside this route, i.e. from a foreign IP (seen 1405-08-22: 403 from azmoon.iau.ir and sanjesh.org).
        # Serve the final page here and pin relative links to its real address with <base>.
        tag = ('<base href="%s">' % x["final"].replace('"', "%22")).encode()
        i = body.lower().find(b"<head")
        j = body.find(b">", i) + 1 if i >= 0 else 0
        body = body[:j] + tag + body[j:]
    hdrs = {"content-type": x["ctype"] or "application/octet-stream"}
    origin = request.headers.get("origin")
    if origin:                                                # the relay drops CORS headers; restore them
        hdrs["access-control-allow-origin"] = origin
        hdrs["access-control-allow-credentials"] = "true"
    host = urlparse(x["final"]).hostname or urlparse(u).hostname
    for c in (x["headers"].get("set-cookie") or []):
        name, _, rest = c.partition("=")
        if name and host:
            try:
                ctx.add_cookies([{"name": name.strip(), "value": rest.split(";")[0], "domain": host, "path": "/"}])
            except Exception:                                 # noqa: BLE001
                pass
    st = x["status"] or 502
    if 300 <= st < 400:
        stats["relay_failed"] += 1                            # redirect loop the relay could not finish
        return route.abort()
    return route.fulfill(status=st, headers=hdrs, body=body)


def pdf_doc(a, x):
    """An Iranian PDF read whole through the relay; text with pypdf, the file kept next to --out."""
    path = (os.path.splitext(a.out)[0] if a.out else "page") + ".pdf"
    open(path, "wb").write(x["body"])
    text, pages = "", 0
    try:
        from pypdf import PdfReader
        r = PdfReader(path)
        pages = len(r.pages)
        text = "\n".join((pg.extract_text() or "") for pg in r.pages)
    except Exception as e:                                    # noqa: BLE001
        print(f"::warning::PDF text extraction failed - {str(e)[:120]}", file=sys.stderr)
    doc = {"url": a.url, "title": os.path.basename(urlparse(a.url).path), "text": text, "links": [],
           "via": "iran-relay-raw-pdf", "file": path,
           "stats": {"text_chars": len(text), "pdf_pages": pages, "bytes": len(x["body"]),
                     "links_total": 0, "links_shown": 0, "scrolled": 0, "wait": "relay-raw",
                     "shell": len(text) < a.min_chars}}
    if a.out:
        open(a.out, "w", encoding="utf-8").write(json.dumps(doc, ensure_ascii=False, indent=1))
    print(f"{a.url} via iran-relay - PDF {pages} pages, {len(text)} chars, {len(x['body'])} bytes -> {path}")
    return 1 if doc["stats"]["shell"] else 0


def relay_raw_doc(a):
    """No browser here: the relay's raw HTML only (a client-rendered page stays a shell)."""
    import web_extract as W
    try:
        status, headers, raw, _t, _n = W.fetch(a.url, 60, 3_000_000)
    except Exception as e:                                    # noqa: BLE001
        print(f"::error::{a.url} via iran-relay - {str(e)[:160]}", file=sys.stderr)
        return 1
    html, _cs = W.decode(raw, headers)
    p = W.Reader(a.url, False, main_only=False)
    p.feed(html)
    text = p.text()
    links = sorted({l for l in p.links if l.startswith(("http://", "https://"))})
    doc = {"url": a.url, "title": p.title, "text": text, "links": links[:400], "via": "iran-relay",
           "stats": {"text_chars": len(text), "links_total": len(links),
                     "links_shown": min(len(links), 400), "scrolled": 0, "wait": "relay-raw",
                     "shell": len(text) < a.min_chars}}
    if a.out:
        open(a.out, "w", encoding="utf-8").write(json.dumps(doc, ensure_ascii=False, indent=1))
    print(f"{a.url} via iran-relay - {len(text)} chars" + (" - still a shell" if doc["stats"]["shell"] else ""))
    return 1 if doc["stats"]["shell"] else 0


if __name__ == "__main__":
    sys.exit(main())
