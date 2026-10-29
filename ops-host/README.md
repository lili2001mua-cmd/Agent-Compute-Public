# ops-host — ops.hohedoc.ir

Pull-deployed PHP package for the holding's **system workflows**: watchdogs, guards and periodic upkeep that
leave GitHub Actions. Its own subdomain under the user's rule (1405-08-07). Same deploy pattern as `apps-host/`
(manifest with sha256, `deploy.php` pulled by cron).

| private workflow it replaces | how |
|---|---|
| `nightly-sweep-watchdog.yml` (routine freshness) | every push of a routine's ledger (`Memory-Workflow/<id>/…`) to the private repo's `main` sends a beat from that repo's `pre-push` hook (`Sys.scripts/ops-beat.py`) to `beat.php`. `app/tick.php` alerts once on Telegram when a beat is later than its limit, and once more when it recovers. |

Watched routines and their limits: `OPS_WATCH` in `files/app/lib.php`.

| scheduled Claude routines | **moved to `routine.hohedoc.ir`** (`routine-host/`, 1405-08-07) — one clock only; `fire.php` was removed here. |

| `auto-pr-stranded-branches.yml` (private repo) | `app/stranded.php`, once a day at 03:xx UTC: every `claude/*` branch of Agent-Project-Backup with hand-written unmerged work and no PR gets one PR (REST only; same rules and the same generated-file list as `Sys.scripts/lib/branch-classify.sh`). Errors go to Telegram. |

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
side cannot see sibling folders). Alerts go to the trainer control channel. No GitHub token is read.

## Install (once)

1. Create subdomain `ops.hohedoc.ir` with SSL in DirectAdmin.
2. Upload `bootstrap/deploy.php` to `domains/ops.hohedoc.ir/`.
3. Cron every 5 min: `/usr/local/bin/php /home/<user>/domains/ops.hohedoc.ir/deploy.php`
   (one line runs both the deploy and the watchdog tick).
4. Check `https://ops.hohedoc.ir/status.php`: `deployed`, `last_tick_age_s` under 900, `bot_token_seen_by_cron: true`.

After any change under `files/`, run `python3 ops-host/build-manifest.py`.
