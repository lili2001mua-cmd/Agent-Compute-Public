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

## Higgsfield with the owner's app credits (MCP login)

The server-side key (`HF_API_KEY_*`) spends a separate API wallet. To spend the **app** credits — the same
wallet the claude.ai connector uses — the host logs into Higgsfield's MCP server once with the owner's
account: `job=hf_login_link` → open the link in a browser → sign in → tokens land in `var/hf-oauth.json`.
Then `hf_tools` lists the MCP tools and `hf_call` (tool + JSON args) runs one, refreshing tokens itself.
The client is identified by `public_html/oauth/client.json` (OAuth client-metadata document, PKCE, no secret).

## Extra Google Drive accounts (BACKUP, …) — no GitHub secret

`job=drive_login_link account=BACKUP` → a one-time link (`drive-login.php?k=…`, 15 minutes, single use).
The first time, that page asks for the Google **Web application** client (id + secret) and stores it in
`var/drive-web-client.json` (0600). Google returns to `oauth/drive-callback.php`, which stores the account's
refresh token in `var/drive-accounts/<NAME>.json` (0600). Nothing is printed, nothing goes to GitHub.
`job=drive_accounts` lists connected accounts; `job=drive_list account=BACKUP` is the read-only proof
(email, quota, 20 root names). Code: `app/drive_accounts.php`. Since 1405-08-13 BACKUP is the holding's only Drive account (`job=drive_set_default`).

## My-Sites backups (encrypted, key never leaves this host)

`app/my_sites.php`. `mysites_pubkey` (inline) makes the X25519 key once in `var/my-sites-key.json` (mode 0600,
never overwritten) and returns only the public key. Sessions encrypt with it (`Sys.scripts/my_sites_upload.py` of
Agent-Project-Backup, format MYS1) and can never decrypt. `mysites_restore` (queued) downloads one My-Sites file,
decrypts it here, compares `sha256_plain`/`sha256_enc` with the file's `appProperties`, keeps nothing decrypted, and
writes the last result to `var/mysites-restore.json`. For the owner's offline copy of the key: download
`var/my-sites-key.json` once from the DirectAdmin file manager into a password manager; lose both and the backups
cannot be opened.

## Door

`POST https://studio.hohedoc.ir/run.php` with header `X-Studio-Key: <ADMIN_KEY>` and form fields
`job=<name>` plus the job's arguments. The key is never accepted in a query string. 20 wrong keys in
10 minutes close the door for the rest of the window. Queued jobs answer `{"queued":true,"id":…}`;
poll with `job=result&id=<id>`. `status.php` is public and shows only booleans and versions.

## Secrets (`secrets.php` beside `deploy.php`, outside `public_html`, chmod 600)

```php
<?php return [
  'ADMIN_KEY'         => '<new random ≥32 chars — not the trainer key>',
  'HF_API_KEY_ID'     => '…',
  'HF_API_KEY_SECRET' => '…',
  // 'HF_DAILY_MAX'   => '30',
];
```

Drive keeps no secret here: the BACKUP account's client and refresh token live in `var/` (connected with
`drive-login.php`), and `var/drive-default.json` makes it the host's only Drive. `tick.php` sets both secret files to 0600 by itself.
No Telegram session and no GitHub token are read.

## Install (once)

1. Create subdomain `studio.hohedoc.ir` with SSL in DirectAdmin.
2. Upload `bootstrap/deploy.php` and `secrets-extra.php` to `domains/studio.hohedoc.ir/`.
3. Cron every 5 min: `/usr/local/bin/php …/studio.hohedoc.ir/deploy.php`
4. Cron every 1 min: `/usr/local/bin/php …/studio.hohedoc.ir/app/tick.php`
5. Check `https://studio.hohedoc.ir/status.php`: `gd_webp`, `secrets_present`, `last_tick_age_s`.

After any change under `files/`, run `python3 studio-host/build-manifest.py`.
