# control-host — control.hohedoc.ir

Control server of the **My-Control-Project** Android app (private repo:
`Memory-Software-Builder/Android/My-Control-Project/`, design: `Memory-Workflow/idea-orchestrations/holding-control-connection/HANDBOOK.md`).
Pull-deployed PHP package, same pattern as `ops-host/` (manifest with sha256, `deploy.php` pulled by cron).

| door | what |
|---|---|
| `GET api.php?op=ping` | unsigned; `{"ok":true,"secrets":bool}` — nothing else |
| `GET api.php?op=status` | signed; live state and latest run of every workflow of the private repo (cached by the cron tick every 5 min) |
| `POST api.php?op=toggle-request` | signed; `{workflow, action, reason}` ⇒ a challenge (10 min) — retired workflows are refused |
| `POST api.php?op=toggle-confirm` | signed; `{challenge}` ⇒ GitHub enable/disable **and** both registry rows (`workflow-state.yaml`, `workflow-execution-controls.json`) in one `[skip ci]` commit; if the commit fails the GitHub state is rolled back |

Signature: headers `X-HC-Device`, `X-HC-Ts`, `X-HC-Nonce`, `X-HC-Sig` = hex HMAC-SHA256(device key,
`op \n ts \n nonce \n sha256(body)`). Clock window 300 s, each nonce once. The phone holds only its device
key (Android Keystore); the GitHub token (W-HOST) lives only in `/home/<user>/holding-vault/gh-write-host.php` outside every domain, and the cron copies it into `var/` (handbook `github-four-tokens`). Nothing here talks to Claude.

## Setup (user, once)

1. DirectAdmin: add subdomain `control.hohedoc.ir` with SSL.
2. Upload `bootstrap/deploy.php` to `domains/control.hohedoc.ir/` (outside `public_html`).
3. Copy `bootstrap/secrets.php.example` to `domains/control.hohedoc.ir/secrets.php` and put the device key in it (the GitHub token is not here).
4. Cron every 5 minutes: `/usr/local/bin/php /home/<account>/domains/control.hohedoc.ir/deploy.php`
5. Check: `https://control.hohedoc.ir/api.php?op=ping` ⇒ `{"ok":true,"secrets":true,…}`.

Note: the DirectAdmin subdomain's web root must be `domains/control.hohedoc.ir/public_html`
(the same layout as the other hohedoc.ir subdomains).

## Database backup button

`POST api.php?op=backup-request {"target":"redlilium-db"}` (signed) drops one request file in `var/backup-req/`;
`sites.hohedoc.ir` runs it (see `sites-host/README.md`, «Phone button»). `GET api.php?op=backup-status` (signed)
returns the last result and how many requests are waiting.

## Trainer project-control board (`app/board.php`)

Every cron tick (5 min) builds `var/trainer-board/board.json` from the trainer's own records in the private repo
(mapping ledger, probes, close runs, feedback, pool, report heads, the commits that carry each session), with the
vault token. It is the port of `Sys.scripts/step3-board-build.py`; no session writes anything here and no key lives
in any Claude account. Titles were seeded once from the repo's `step3-board/titles.json` and now live only here.
`GET api.php?op=trainer-board` (signed) serves it to the app.

The same tick also builds `queue` inside `board.json`: the «Routine Trainer List» (bundles waiting for step 3 —
pool status with an archive and not closed, grouped by the identity taxonomy, urgent first). `step3-fire` accepts a
bundle from either list.

## Projects section of the app (`app/projects.php`)

User decision 1405-08-18: the app's «پروژه‌ها» must follow the repo live, not a new app build. Every cron tick reads
`Memory-Workflow/idea-orchestrations/*/IDEA-ORCH.md` of the private repo (only docs whose blob sha changed are
re-parsed; the last real commit of each doc gives «last change») and the category rules in
`Memory-Software-Builder/Android/My-Control-Project/orch-board/orch-categories.json`, and writes
`var/projects/projects.json`. `GET api.php?op=projects` (signed) serves it. Steady state costs 3 GitHub calls per tick.
The parser is a port of the private repo's `Sys.scripts/orch-board-build.py`; parity check on a checkout:
`php -r 'require "control-host/files/app/projects.php"; echo json_encode(projects_from_local("<Agent-Project-Backup>"));'`
must equal `orch_groups` of `source/tools/build-snapshot.py` (1405-08-18: 90 orchestras, 0 differences).

