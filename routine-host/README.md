# routine-host — routine.hohedoc.ir

The holding's **one clock for scheduled routines** (user's rule, 1405-08-07: time and execution live on the
host, never on a Claude account). Same pull-deploy pattern as `ops-host/` (manifest with sha256, `deploy.php`
pulled by cron). Design and the six-expert sign-off: private repo,
`Memory-Workflow/idea-orchestrations/routine-host-consolidation/PANEL-NOTEBOOK.md`.

## How it works

1. `app/jobs.json` lists the routines. It is **generated** in the private repo from
   `Sys.employee-management/registry.yaml` (`Sys.scripts/build-routine-jobs.py`) — never edit it by hand. It
   carries no email, no trigger id and no secret.
2. Every 5 minutes `app/tick.php` finds each routine's due slot and opens **one run per slot**
   (`run_id = <job>-<Tehran yyyymmdd-hhmm>`).
3. `mode: shadow` → the run is only recorded (the old Claude trigger still does the work).
   `mode: live` → the host fires a Claude account's **dispatcher** routine with the body `job=<id> run=<run_id>`.
4. The only proof of done is the run file (`done_path`) with `"status": "done"` on the private repo's `main`,
   read with a read-only token. A 2xx from Claude only means a session was created. A read error is "unknown",
   never "not done".
5. When a run cannot be fired, or does not finish in time, the host builds a **manual package**: a 72-hour link
   (`/p.php?t=…`) sent to the trainer control channel. Paste its text into any Claude session that has the private
   repo; the host notices the done file and says so on Telegram.

| answer from Claude | what the host does |
|---|---|
| 2xx | waits for the done file; no other account |
| 401 / 404 | key marked dead, one Telegram alert, next account |
| 403 | **halts all firing** (maybe region or organisation), manual package |
| 400 | manual package, no rotation |
| 429 (usage cap) | **never another account**; key cools down, manual package |
| 5xx / network | key cools 30 min, next account |

Firing stays **off** until `app/dispatch.flag` says `armed` (a commit in this repo) **and** the host's own
country probe shows a supported country. Until then every live run becomes a manual package.

## Keys (one dispatcher per repository, named after it)

Rule (1405-08-08): in each Claude account, every repository a routine works on gets **its own** dispatcher
routine, **named `<alias>-<Repository>`** (`acct-a-Agent-Project-Backup`, `acct-a-stylistcrm`, …), so the routine list
on claude.ai shows at a glance what each one is for. When the same repository also needs the Google Drive
connector, a second dispatcher is named `<Repository>-drive`. Each dispatcher has **no schedule**, only an
**API trigger**, the same thin prompt (it reads `Memory-Routine-Runbook/routine-dispatcher.md` from the private
repo), and these repositories attached: its own repository, plus always `Agent-Project-Backup` (the runbook,
the registry and the done files) and `Agent-Compute-Public` (host packages and the public workflow runner) —
every holding workflow runs on these three: the host, the private repo and the public repo.

Copy the fire URL and token **exactly** from the routine page into a text file:

```
rtk1
alias: acct-a
scope: agent-project-backup
url: <fire URL from the routine page>
token: <token from the routine page>
```

`scope` is the dispatcher's name in lower case (`agent-project-backup`, `agent-project-backup-drive`,
`stylistcrm`). Upload it beside `deploy.php` (the host creates `keys/` itself and moves `*.key` and `*.key.txt` there, dropping `.txt`), or straight into `domains/routine.hohedoc.ir/keys/<alias>-<scope>.key`, e.g.
`keys/acct-a-agent-project-backup.key` (outside `public_html`). Each routine's `dispatcher:` field in the
registry names which scope fires it. The host sets the folder to `0700` and the file to `0600`, reads it
strictly (never `include`), and shows a bad key on the status page by its name only. A `.key` under
`public_html` is refused with an alert. Optional line `beta: <value>` overrides the `anthropic-beta` header.
Never paste a key into a Claude chat.

To revoke: regenerate the token (or delete the dispatcher) on claude.ai, then replace or delete the file.

## Secrets

`secrets.php` beside `deploy.php`:

```php
<?php return [
  'GITHUB_READ_TOKEN' => '<fine-grained: Contents: Read on Agent-Project-Backup only>',
];
```

`TELEGRAM_BOT_TOKEN` is borrowed from `trainer.hohedoc.ir/secrets.php` by the cron only.

## Doors

- `https://routine.hohedoc.ir/` — status page (Persian). `status.php` — the same as JSON. Neither ever shows a
  URL, token or email; keys appear as present / dead only.
- `POST /beat.php` with `run=<run_id>&phase=start|done` — a hint only; it can change nothing.
- `/p.php?t=<64 hex>` — a manual package (only `sha256(t)` is stored).

## Install (once)

1. Subdomain `routine.hohedoc.ir` with SSL in DirectAdmin.
2. Upload `bootstrap/deploy.php` to `domains/routine.hohedoc.ir/`.
3. Cron every 5 min: `/usr/local/bin/php /home/<user>/domains/routine.hohedoc.ir/deploy.php`
4. Open `https://routine.hohedoc.ir/status.php`: `deployed` set, `last_tick_age_s` under 900,
   `bot_token_seen_by_cron: true`, `host_country` a supported country. A first message arrives on Telegram.

After any change under `files/`, run `python3 routine-host/build-manifest.py`.
