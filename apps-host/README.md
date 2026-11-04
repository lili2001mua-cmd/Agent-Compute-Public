# apps-host — apps.hohedoc.ir

Pull-deployed PHP package for the holding's host for **installable apps and workout documents**. Its own
subdomain under the user's rule (1405-08-07): every main topic on the host gets a separate subdomain with its own
`ADMIN_KEY`, code and cron. Same deploy pattern as `studio-host/` (manifest with sha256, `deploy.php` pulled by cron).

| private workflow it replaces | job(s) | runs |
|---|---|---|
| `app-release-publish.yml` + `workout-apk-to-drive.yml` | `app_upload` (→ internal `app_send`): the Claude session builds, signs, runs `release-findings --gate`, zips each apk/aab and posts the zip (md5 required). Drive: `نسخه‌های نصبی/<App>/v<N>/` | queued (cron) |
| `workout-pdf-to-drive.yml` | `doc_part` × n (≤32MB each) + `doc_commit` (→ internal `doc_send`): the session renders the PDFs, bundle and clips; the cron joins the parts, checks md5 and type, then (since 1405-08-11) puts it in the outbox (`public_html/outbox.php` lists and serves it by sha256) and pokes `https://relay.hohe1.ir/intake/pull.php`, which pulls it into `https://relay.hohe1.ir/workout/[subfolder/]`, checks sha256 and merges `index.json`/`index.html` — folder value `relay:workout`; no shared secret; Drive is no longer a destination | queued (cron) |
| (bulk Drive cleanup, 1405-08-10) | `app/drive-trash.php`: the session writes Drive ids into `app/drive-trash.txt` and pushes once; every cron tick moves up to 60 of them to the Drive **trash** (never a permanent delete, so 30 days to undo). Progress: `drive_trash` on `status.php`. One push = one approval for any number of files. A line `restore <id>` brings a file back out of the trash. | cron |

Session side (private repo): `Sys.scripts/app-release.sh drive …` and `Sys.scripts/workout_pdf_drive.py send …`.

## Door

`POST https://apps.hohedoc.ir/run.php` with header `X-Apps-Key: <ADMIN_KEY>`, or — no stored key at all — `X-Apps-Nonce` +
`X-Apps-Ref` after pushing `apps-auth/<sha256(nonce)>.json` to a branch of this repo (`Sys.scripts/apps_host_auth.py` in the
private repo does it), and form fields `job=<name>` plus the job's arguments. The key is never accepted in a query string. 20 wrong keys in 10 minutes close the door for the rest
of the window. Queued jobs answer `{"queued":true,"id":…}`; poll with `job=result&id=<id>`. `status.php` is public and
shows only booleans and versions.

## Secrets (`secrets.php` beside `deploy.php`, outside `public_html`)

```php
<?php return [
  'ADMIN_KEY'     => '<new random ≥32 chars — not the studio or trainer key>',
];
```

Workout programs reach the Iran relay without any secret (outbox + relay pull, 1405-08-11). Optional `'RELAY_SECRET'` in `secrets-extra.php` switches to the push path (`deploy/sync.php` action `chunk`); `status.php` shows `secrets_present.RELAY_SECRET`.

Drive is the holding's single account (BACKUP), read by the cron from `studio.hohedoc.ir/var` (`hohe_backup_drive`); this host keeps no Google key. The web side cannot see sibling folders (`open_basedir`), but the cron can, and every Drive upload runs in the cron; `status.php` shows both views (`secrets_present`, `secrets_present_cron`). `tick.php` sets the secret files to 0600 by itself.
No Telegram session, no GitHub token and no Higgsfield key are read.

## Install (once)

1. Create subdomain `apps.hohedoc.ir` with SSL in DirectAdmin.
2. Upload `bootstrap/deploy.php` and `secrets.php` to `domains/apps.hohedoc.ir/`.
3. Cron every 5 min: `/usr/local/bin/php …/apps.hohedoc.ir/deploy.php`
4. Cron every 1 min: `/usr/local/bin/php …/apps.hohedoc.ir/app/tick.php`
5. Check `https://apps.hohedoc.ir/status.php`: `secrets_present`, `last_tick_age_s`, `zip`.
6. Optional: the same `ADMIN_KEY` as `APPS_ADMIN_KEY` in an operator's environment. Sessions log in through the repo instead.

Live since 1405-08-07: first real upload, a 28 MB app zip, md5 verified by Drive in 79 s.

After any change under `files/`, run `python3 apps-host/build-manifest.py`.
