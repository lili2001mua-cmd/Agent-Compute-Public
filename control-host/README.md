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
