# ops-host — ops.hohedoc.ir

Pull-deployed PHP package for the holding's **system workflows**: watchdogs, guards and periodic upkeep that
leave GitHub Actions. Its own subdomain under the user's rule (1405-08-07). Same deploy pattern as `apps-host/`
(manifest with sha256, `deploy.php` pulled by cron).

| private workflow it replaces | how |
|---|---|
| `nightly-sweep-watchdog.yml` (routine freshness) | every push of a routine's ledger (`Memory-Workflow/<id>/…`) to the private repo's `main` sends a beat from that repo's `pre-push` hook (`Sys.scripts/ops-beat.py`) to `beat.php`. `app/tick.php` alerts once on Telegram when a beat is later than its limit, and once more when it recovers. |

Watched routines and their limits: `OPS_WATCH` in `files/app/lib.php`. Since 1405-08-11 this also watches the Iranian host: `relay.hohe1.ir/hc-watch.php` posts `id=iran-relay` every ~10 minutes; a gap over 1 hour sends one Telegram alert (Iran host down, or its outbound link cut), and `status.php` → `routines.iran-relay.age_h` is how `mcp-connections-audit.py --verify` in the private repo refreshes the Iran row without reaching Iran itself.

| scheduled Claude routines | **moved to `routine.hohedoc.ir`** (`routine-host/`, armed 1405-08-08): one clock only; `fire.php` removed here (its `INVARIANTS_FIRE_*` secrets were never set). |

| `auto-pr-stranded-branches.yml` (private repo) | `app/stranded.php`, once a day at 03:xx UTC: every `claude/*` branch of Agent-Project-Backup with hand-written unmerged work and no PR gets one PR (REST only; same rules and the same generated-file list as `Sys.scripts/lib/branch-classify.sh`). Errors go to Telegram. |

| `auto-pr-stranded-branches-watchdog.yml` (private repo) | the same file: one Telegram alert when the daily job has not finished for 30 h (and one when it recovers), and one when `OPS_GITHUB_TOKEN` is missing. |

| `delete-branch.yml` and `reconcile-branch.yml` (Agent-Project-Backup, Gemini-Agent-Backup, ChatGPT-Agent-Backup; the two site repos `stylistcrm` and `stylistdirectory` moved to `sites.hohedoc.ir` on 1405-08-08 — same queue, that host answers them) | `app/branchq.php`: every tick it reads `Memory-Workflow/branch-ops/queue/<id>.json` on Agent-Project-Backup `main` and answers in `results/<id>.json` (same guards: only fully merged branches are deleted; reconcile rescues RESCUE_RE files, refuses unknown unique files; never `main`). A daily probe checks the token on those three repos. |

| `sync-routing-digest.yml` (GitHub half) | `app/routing.php`: every 30 min it rebuilds the digest and the two consumer hooks from Agent-Project-Backup `main` (byte-identical to `Sys.scripts/sync-routing-digest.py`) and writes them to each repo of `routing-consumers.txt` only when they differ. The Google Drive half (`app/drive.php`, the `Agent-Repo-Bridge` folder) was retired on 1405-08-10 at the user's request; nothing on this host writes to Google Drive any more. |

| landing queue (ر۷ of the private repo's `commit-path-repair`, 1405-08-19) | `app/lander.php`, every tick: mode from Agent-Project-Backup `main` → `Sys.registry/commit-path-flags.json` (`flags.r7_land`). `shadow` writes **only** `refs/heads/shadow/main`: for each `land/<session>` evidence commit (first parent = candidate, message `land-evidence: {…}`) it fast-forwards shadow/main to the candidate only while shadow/main still equals the evidence base (CAS), else answers `rebase`; afterwards shadow/main follows `main` for writers outside the queue. No guard, no generator, no git on the host. `live` is refused by this version. Answers: `land.php?s=<session>`; `status.php` → `lander` (mode, `sha256` of `app/lander.php`, last round). The private repo replays this same file locally (`Sys.scripts/land-replay.php`) and checks the sha256. |

| main debt deadlines (ر۹ of `commit-path-repair`, 1405-08-19) | `app/debt.php`, once an hour: reads `Memory-Workflow/invariants-runs/debt-ledger.json` from Agent-Project-Backup `main` (built by `Sys.scripts/debt-ledger.py` after every local invariants run). One Telegram alert per red step past its 48 h deadline (with its owner), one recovery message when it leaves the ledger. `status.php` → `debt` (counts only). |

## Doors

- `POST https://ops.hohedoc.ir/beat.php` with `id=<routine>` [`sha=<commit>`]. No key: a forged beat can only
  delay one alert. Unknown ids get 404; one beat per id per 10 minutes.
- `https://ops.hohedoc.ir/status.php` is public and shows only ages and booleans.
- `https://ops.hohedoc.ir/land.php[?s=<session>]` is public: the landing queue's latest answer per session (JSON, no secret).

## Secrets

`secrets.php` beside `deploy.php` (outside `public_html`). Routine fire keys do **not** live here any more:
they belong to `routine.hohedoc.ir` (`routine-host/README.md`).

```php
<?php return [
  'OPS_GITHUB_TOKEN'      => '<fine-grained: Contents RW + Pull requests RW on the holding repos>',
];
```

Nothing else of its own. `TELEGRAM_BOT_TOKEN` is borrowed from `trainer.hohedoc.ir/secrets.php` by the cron only (the web
side cannot see sibling folders). Alerts go to the trainer control channel. `OPS_GITHUB_TOKEN` (fine-grained: Contents, Pull requests and Workflows read/write on the five holding repos) is the only GitHub token.

## Install (once)

1. Create subdomain `ops.hohedoc.ir` with SSL in DirectAdmin.
2. Upload `bootstrap/deploy.php` to `domains/ops.hohedoc.ir/`.
3. Cron every 5 min: `/usr/local/bin/php /home/<user>/domains/ops.hohedoc.ir/deploy.php`
   (one line runs both the deploy and the watchdog tick).
4. Check `https://ops.hohedoc.ir/status.php`: `deployed`, `last_tick_age_s` under 900, `bot_token_seen_by_cron: true`.

After any change under `files/`, run `python3 ops-host/build-manifest.py`.
