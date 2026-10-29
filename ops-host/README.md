# ops-host — ops.hohedoc.ir

Pull-deployed PHP package for the holding's **system workflows**: watchdogs, guards and periodic upkeep that
leave GitHub Actions. Its own subdomain under the user's rule (1405-08-07). Same deploy pattern as `apps-host/`
(manifest with sha256, `deploy.php` pulled by cron).

| private workflow it replaces | how |
|---|---|
| `nightly-sweep-watchdog.yml` (routine freshness) | every push of a routine's ledger (`Memory-Workflow/<id>/…`) to the private repo's `main` sends a beat from that repo's `pre-push` hook (`Sys.scripts/ops-beat.py`) to `beat.php`. `app/tick.php` alerts once on Telegram when a beat is later than its limit, and once more when it recovers. |

Watched routines and their limits: `OPS_WATCH` in `files/app/lib.php`.

| scheduled Claude routines (user's decision 1405-08-07: the clock lives on the host, not on the Claude account) | `app/fire.php`: every 48 h at 17:xx UTC (21:17–22:17 Tehran) it POSTs to the routine's fire URL; a non-2xx answer is sent to Telegram. Jobs: `OPS_FIRE`. |

## Doors

- `POST https://ops.hohedoc.ir/beat.php` with `id=<routine>` [`sha=<commit>`]. No key: a forged beat can only
  delay one alert. Unknown ids get 404; one beat per id per 10 minutes.
- `https://ops.hohedoc.ir/status.php` is public and shows only ages and booleans.

## Secrets

`secrets.php` beside `deploy.php` (outside `public_html`), only for the routine clock:

```php
<?php return [
  'INVARIANTS_FIRE_URL'   => '<fire URL from the routine page on claude.ai/code/routines>',
  'INVARIANTS_FIRE_TOKEN' => '<token from the same page>',
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
