# studio-host — studio.hohedoc.ir

Pull-deployed PHP package for the holding's image/video host. It replaces five private-repo
GitHub Actions workflows so they spend zero Actions minutes. Same deploy pattern as `trainer-host/`
(manifest with sha256, `deploy.php` pulled by cron), but a separate subdomain with its own,
smaller set of secrets.

| private workflow it replaces | job(s) | runs |
|---|---|---|
| `higgsfield.yml` | `hf_check` · `hf_submit` · `hf_status` | inline |
| `visual-assets-to-drive.yml` | `asset_upload` (→ internal `asset_send`) · `asset_import` | queued (cron) |
| `remotion-render.yml` | render inside the Claude Code session, then `asset_upload` | — |
| `sd-photos-inventory.yml` | `sd_inventory` | queued |
| `sd-photos-fetch.yml` | `sd_fetch` → zip behind `get.php?t=<token>` (6 h, deleted after first download) | queued |

## Door

`POST https://studio.hohedoc.ir/run.php` with header `X-Studio-Key: <ADMIN_KEY>` and form fields
`job=<name>` plus the job's arguments. The key is never accepted in a query string. 20 wrong keys in
10 minutes close the door for the rest of the window. Queued jobs answer `{"queued":true,"id":…}`;
poll with `job=result&id=<id>`. `status.php` is public and shows only booleans and versions.

## Secrets (`secrets.php` beside `deploy.php`, outside `public_html`, chmod 600)

```php
<?php return [
  'ADMIN_KEY'         => '<new random ≥32 chars — not the trainer key>',
  'DRIVE_OAUTH'       => "CLIENT_ID=…\nCLIENT_SECRET=…\nREFRESH_TOKEN=…",
  'DRIVE_ROOT_ID'     => '<Memory-Cloud folder id>',
  'HF_API_KEY_ID'     => '…',
  'HF_API_KEY_SECRET' => '…',
  // 'HF_DAILY_MAX'   => '30',
];
```

No Telegram session and no GitHub token live here. One optional `secrets-extra.php` (same shape)
is merged on top.

## Install (once)

1. Create subdomain `studio.hohedoc.ir` with SSL in DirectAdmin.
2. Upload `bootstrap/deploy.php` and `secrets.php` to `domains/studio.hohedoc.ir/`.
3. Cron every 5 min: `/usr/local/bin/php …/studio.hohedoc.ir/deploy.php`
4. Cron every 1 min: `/usr/local/bin/php …/studio.hohedoc.ir/app/tick.php`
5. Check `https://studio.hohedoc.ir/status.php`: `gd_webp`, `secrets_present`, `last_tick_age_s`.

After any change under `files/`, run `python3 studio-host/build-manifest.py`.
