# ops-host — ops.hohedoc.ir

Pull-deployed PHP package for the holding's **system workflows**: watchdogs, guards and periodic upkeep that
leave GitHub Actions. Its own subdomain under the user's rule (1405-08-07). Same deploy pattern as `apps-host/`
(manifest with sha256, `deploy.php` pulled by cron).

| private workflow it replaces | how |
|---|---|
| `nightly-sweep-watchdog.yml` (routine freshness) | every push of a routine's ledger (`Memory-Workflow/<id>/…`) to the private repo's `main` sends a beat from that repo's `pre-push` hook (`Sys.scripts/ops-beat.py`) to `beat.php`. `app/tick.php` alerts once on Telegram when a beat is later than its limit, and once more when it recovers. |

Watched routines and their limits: `OPS_WATCH` in `files/app/lib.php`.

| scheduled Claude routines | `app/fire.php` fires `invariants` every 48 h **until `routine.hohedoc.ir` is armed** (`routine-host/`, 1405-08-07); that day this file is removed so only one clock remains. Secrets: `INVARIANTS_FIRE_URL` / `INVARIANTS_FIRE_TOKEN` in `secrets.php`. |

| `auto-pr-stranded-branches.yml` (private repo) | `app/stranded.php`, once a day at 03:xx UTC: every `claude/*` branch of Agent-Project-Backup with hand-written unmerged work and no PR gets one PR (REST only; same rules and the same generated-file list as `Sys.scripts/lib/branch-classify.sh`). Errors go to Telegram. |

| `auto-pr-stranded-branches-watchdog.yml` (private repo) | the same file: one Telegram alert when the daily job has not finished for 30 h (and one when it recovers), and one when `OPS_GITHUB_TOKEN` is missing. |

| `delete-branch.yml` and `reconcile-branch.yml` (five holding repos) | `app/branchq.php`: every tick it reads `Memory-Workflow/branch-ops/queue/<id>.json` on Agent-Project-Backup `main` and answers in `results/<id>.json` (same guards: only fully merged branches are deleted; reconcile rescues RESCUE_RE files, refuses unknown unique files; never `main`). A daily probe checks the token on all five repos. |

| `sync-routing-digest.yml` (GitHub half) | `app/routing.php`: every 30 min it rebuilds the digest and the two consumer hooks from Agent-Project-Backup `main` (byte-identical to `Sys.scripts/sync-routing-digest.py`) and writes them to each repo of `routing-consumers.txt` only when they differ. The Google Drive half needs a service-account key and is not on the host yet. |

## Doors

- `POST https://ops.hohedoc.ir/beat.php` with `id=<routine>` [`sha=<commit>`]. No key: a forged beat can only
  delay one alert. Unknown ids get 404; one beat per id per 10 minutes.
- `https://ops.hohedoc.ir/status.php` is public and shows only ages and booleans.

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
