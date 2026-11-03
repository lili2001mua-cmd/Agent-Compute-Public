#!/usr/bin/env python3
"""Digikala search through the holding's Iranian relay - same outputs as digikala_render.py.

Why not a browser any more: from inside Iran the JSON API answers a raw request with
200 (measured 1405-08-06, 20 products in 0.47 s), so the WAF cookie dance that forced
Playwright here is gone. Iranian sites must not be fetched from this runner anyway.

Config: REQUEST (JSON) exactly as digikala_render.py - queries, must_have, must_not,
min_price, max_price, max_pages, top_n. `pages` is ignored (it was a browser warm-up).
Output: out/render-listing.json, render-details.json, render-report.json.
"""
import json
import os
import sys
import time
from urllib.parse import quote

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import iran_relay  # noqa: E402

OUT = os.environ.get("OUT_DIR", "out")
API = "https://api.digikala.com"
DEFAULT_CFG = {"queries": [], "must_have": [], "must_not": [], "min_price": 0,
               "max_price": 10 ** 12, "max_pages": 3, "top_n": 12}


def main():
    os.makedirs(OUT, exist_ok=True)
    raw = json.loads(os.environ.get("REQUEST", "").strip() or "{}")
    cfg = {**DEFAULT_CFG, **(raw if isinstance(raw, dict) else {})}
    if not cfg["queries"]:
        raise SystemExit("::error::REQUEST has no 'queries'.")
    report = {"requested": 0, "ok": 0, "failed": [], "pages": [], "cfg": cfg, "via": "iran-relay"}
    products, details = {}, []

    def save(name, obj):
        with open(f"{OUT}/{name}", "w", encoding="utf-8") as f:
            json.dump(obj, f, ensure_ascii=False, indent=1)

    def flush():
        save("render-listing.json", {"count": len(products), "products": list(products.values())})
        save("render-details.json", details)
        save("render-report.json", report)

    def walk(node, sink):
        if isinstance(node, dict):
            if isinstance(node.get("id"), int) and isinstance(node.get("title_fa"), str):
                sink[node["id"]] = node
            for v in node.values():
                walk(v, sink)
        elif isinstance(node, list):
            for v in node:
                walk(v, sink)

    def api(url):
        report["requested"] += 1
        last = None
        for i in range(3):
            try:
                d = iran_relay.job("fetch", url, timeout=60)
                if d.get("code") == 200:
                    report["ok"] += 1
                    return json.loads(d.get("body") or "null")
                last = f"code {d.get('code')} {d.get('error') or d.get('err') or ''}"[:200]
                if d.get("error") in ("daily-cap", "host-not-allowed"):
                    break
            except Exception as e:  # noqa: BLE001
                last = repr(e)[:200]
            time.sleep(2 * (i + 1))
        report["failed"].append({"url": url, "error": last})
        return None

    def price_of(it):
        return ((it.get("default_variant") or {}).get("price") or {}).get("selling_price") or 0

    try:
        for term in cfg["queries"]:
            for p in range(1, cfg["max_pages"] + 1):
                d = api(f"{API}/v1/search/?q={quote(term)}&page={p}")
                if not d:
                    break
                before = len(products)
                walk(d, products)
                report["pages"].append({"url": f"search:{term}:{p}", "html_len": 0,
                                        "not_found": False, "products_so_far": len(products)})
                if len(products) == before:
                    break
                time.sleep(1)
        flush()

        def is_target(it):
            t = it.get("title_fa") or ""
            if it.get("status") != "marketable":
                return False
            if cfg["must_have"] and not any(w in t for w in cfg["must_have"]):
                return False
            if any(w in t for w in cfg["must_not"]):
                return False
            return cfg["min_price"] <= price_of(it) <= cfg["max_price"]

        pool = sorted((p for p in products.values() if is_target(p)),
                      key=lambda x: -(((x.get("rating") or {}).get("count")) or 0))
        targets = pool[:cfg["top_n"]]
        report["targets"] = len(targets)
        for it in targets:
            pid = it["id"]
            d = api(f"{API}/v2/product/{pid}/")
            cm = []
            for cp in (1, 2, 3):
                c = api(f"{API}/v1/product/{pid}/comments/?page={cp}")
                got = ((c or {}).get("data") or {}).get("comments") or []
                got = got if isinstance(got, list) else []
                cm.extend(got)
                if len(got) < 10:
                    break
                time.sleep(1)
            details.append({"id": pid, "title": it.get("title_fa"), "detail": d, "comments": cm})
            time.sleep(1)
    finally:
        flush()
    print(json.dumps({"products": len(products), "detailed": len(details), "targets": report.get("targets"),
                      "requested": report["requested"], "ok": report["ok"]}, ensure_ascii=False))


if __name__ == "__main__":
    main()
