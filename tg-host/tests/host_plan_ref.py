#!/usr/bin/env python3
"""Python reference for the host night plan (B1) — runs the REAL Agent-Project-Backup code on a fixture.

Usage: python3 host_plan_ref.py <fixture.json>   (APB_ROOT = Agent-Project-Backup checkout, default ../Agent-Project-Backup
       next to this repo). Prints {"since","until","keys","bundles"} as JSON.

Real code used: nightly_pool.window() (the night window), telegram_pool.parse_window / group_bundles (→ tg_grouping) /
in_window / bundle_key / media_type, and real Telethon Message objects (so photo/video/document/media follow Telethon).
Copied, because they are inline in telegram_pool.run() / nightly-pool.yml and cannot be called: the read loop
(newest first, stop at the first message older than since - 1 h), the window_keys comprehension, and the archive filter.
"""
import json
import os
import sys
import types as _t
from datetime import date, datetime, timedelta, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
APB = os.environ.get("APB_ROOT") or os.path.normpath(os.path.join(HERE, "..", "..", "..", "Agent-Project-Backup"))
sys.path.insert(0, os.path.join(APB, "Sys.scripts"))
for _n in ("pyaes",):  # telethon imports its AES backend at import time; nothing here encrypts
    try:
        __import__(_n)
    except ImportError:
        sys.modules[_n] = _t.ModuleType(_n)

from telethon.tl import types  # noqa: E402
import nightly_pool  # noqa: E402
import telegram_pool as tp  # noqa: E402

PEER = types.PeerChannel(1)


def dt(t):
    return datetime.fromtimestamp(int(t), tz=timezone.utc)


def photo(p, t):
    if (p or {}).get("_") == "photo":
        return types.Photo(id=1, access_hash=1, file_reference=b"", date=dt(t), sizes=[], dc_id=1)
    return types.PhotoEmpty(id=0)


def attr(a):
    k = a.get("_")
    if k == "documentAttributeVideo":
        return types.DocumentAttributeVideo(duration=1, w=1, h=1, round_message=a.get("round_message") or None)
    if k == "documentAttributeAudio":
        return types.DocumentAttributeAudio(duration=1, voice=a.get("voice") or None)
    if k == "documentAttributeFilename":
        return types.DocumentAttributeFilename(file_name=a.get("file_name", ""))
    raise ValueError(k)


def document(d, t):
    if (d or {}).get("_") == "document":
        return types.Document(id=1, access_hash=1, file_reference=b"", date=dt(t), mime_type=d.get("mime_type", ""),
                              size=1, dc_id=1, attributes=[attr(a) for a in d.get("attributes", [])])
    return types.DocumentEmpty(id=0)


def media(md, t):
    if not md:
        return None
    k = md["_"]
    if k == "messageMediaEmpty":
        return types.MessageMediaEmpty()
    if k == "messageMediaPhoto":
        return types.MessageMediaPhoto(photo=photo(md.get("photo"), t))
    if k == "messageMediaDocument":
        return types.MessageMediaDocument(document=document(md.get("document"), t))
    if k == "messageMediaWebPage":
        w = md.get("webpage") or {}
        if w.get("_") == "webPage":
            return types.MessageMediaWebPage(webpage=types.WebPage(
                id=1, url="https://x", display_url="x", hash=0,
                photo=photo(w["photo"], t) if w.get("photo") else None,
                document=document(w["document"], t) if w.get("document") else None))
        return types.MessageMediaWebPage(webpage=types.WebPageEmpty(id=1))
    raise ValueError(k)


def message(m):
    k = m.get("_", "message")
    if k == "messageEmpty":
        return None  # Telethon's iter_messages never yields MessageEmpty
    if k == "messageService":
        return types.MessageService(id=m["id"], peer_id=PEER, date=dt(m["date"]), action=types.MessageActionPinMessage())
    return types.Message(id=m["id"], peer_id=PEER, date=dt(m["date"]), message=m.get("message", ""),
                         media=media(m.get("media"), m["date"]), grouped_id=m.get("grouped_id"))


def main():
    fx = json.load(open(sys.argv[1], encoding="utf-8"))
    lo, hi = nightly_pool.window(date.fromisoformat(fx["date"]))
    window = tp.parse_window(lo.isoformat(), hi.isoformat())
    newest_first = [x for x in (message(m) for m in sorted(fx["messages"], key=lambda m: -m["id"])) if x is not None]
    # telegram_pool.run(): iter_messages(limit=None), break at the first message older than the floor
    floor = window[0] - timedelta(hours=1)
    msgs = []
    for m in newest_first:
        if m.date and m.date < floor:
            break
        msgs.append(m)
    msgs.reverse()
    bundles = tp.group_bundles(msgs)
    # telegram_pool.run(): window_keys
    window_keys = [tp.bundle_key(b["media"]) for b in bundles
                   if tp.in_window(b, window)
                   and any(tp.media_type(m) in ("video", "photo", "document") for m in b["media"])]
    # nightly-pool.yml plan step: drop keys already in archive/<k>.json
    archived = set(fx.get("archived", []))
    keys = [k for k in window_keys if k not in archived]
    by_key = {tp.bundle_key(b["media"]): b for b in bundles}
    print(json.dumps({"since": lo.isoformat(), "until": hi.isoformat(), "messages_read": len(msgs), "window_keys": window_keys,
                      "keys": keys, "bundles": [{"key": k, "media_ids": [m.id for m in by_key[k]["media"]],
                                                 "types": [tp.media_type(m) for m in by_key[k]["media"]]} for k in keys]},
                     ensure_ascii=False))


if __name__ == "__main__":
    main()
