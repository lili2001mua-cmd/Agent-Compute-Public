# apps-host — apps.hohedoc.ir

Pull-deployed PHP package for the holding's host for **installable apps and workout documents**. Its own
subdomain under the user's rule (1405-08-07): every main topic on the host gets a separate subdomain with its own
`ADMIN_KEY`, code and cron. Same deploy pattern as `studio-host/` (manifest with sha256, `deploy.php` pulled by cron).

| private workflow it replaces | job(s) | runs |
|---|---|---|
| `app-release-publish.yml` + `workout-apk-to-drive.yml` | `app_upload` (→ internal `app_send`): the Claude session builds, signs, runs `release-findings --gate`, zips each apk/aab and posts the zip (md5 required). Drive: `نسخه‌های نصبی/<App>/v<N>/` | queued (cron) |
| `workout-pdf-to-drive.yml` | `doc_part` × n (≤32MB each) + `doc_commit` (→ internal `doc_send`): the session renders the PDFs, bundle and clips; the cron joins the parts, checks md5 and type, uploads into an allow-listed folder (`برنامه‌های تمرین` [/subfolder]) | queued (cron) |

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
  'DRIVE_ROOT_ID' => '<Memory-Cloud folder id>',
];
```

`DRIVE_OAUTH` is borrowed from trainer's or studio's secret files. The web side cannot see sibling folders (`open_basedir`), but the cron can, and every Drive upload runs in the cron; `status.php` shows both views (`secrets_present`, `secrets_present_cron`). `tick.php` sets the secret files to 0600 by itself.
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
