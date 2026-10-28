# deploy-host — deploy.hohedoc.ir

Pull-deployed PHP package that runs the `stylistcrm` deploy queue (`deploy-queue/<id>.json` →
`deploy-results/queue/<id>.json`) with zero GitHub Actions minutes. It replaces the Google Apps
Script channel (`stylistcrm/Sys.scripts/apps-script/lilium-deploy.gs`), which stopped after
1405-07-20 without any visible error. `lilium-deploy.yml` stays as the fallback.

The user's approval stays in the chat: a session writes a queue file only after
«تأیید انتقال به هاست». This host only executes what is already on `main`.

## How it works

Cron runs `app/tick.php` every minute. It lists the queue, skips any request that already has a
result file (so requests the Google channel or Actions already ran are never repeated), reads each
new request at the commit that wrote it, signs the body like `lilium-call.py`, POSTs it to the
site's `deploy/sync.php`, and writes the result back to `main`. Each request runs once.

Every 30 minutes it also sends a read-only `ping` to the three sites. Any failure — an expired
token, a refused signature, a site that is down — lands in `status.php` as `last_error`.

## Secrets (`secrets.php` beside `deploy.php`, outside `public_html`, chmod 600)

```php
<?php return [
  'GITHUB_TOKEN'  => '<fine-grained token: only stylistcrm, Contents: Read and write>',
  'DEPLOY_SECRET' => '<same value as deploy/config.php on the site>',
  // 'NEW_DOMAIN' => 'redlilium.ir',
];
```

Nothing else lives here: no Telegram session, no Drive token, no other repo.

## Install (once)

1. Create subdomain `deploy.hohedoc.ir` with SSL in DirectAdmin.
2. Upload `bootstrap/deploy.php` and `secrets.php` to `domains/deploy.hohedoc.ir/`.
3. Cron every 5 min: `/usr/local/bin/php /home/<user>/domains/deploy.hohedoc.ir/deploy.php`
4. Cron every 1 min: `/usr/local/bin/php /home/<user>/domains/deploy.hohedoc.ir/app/tick.php`
5. Open `https://deploy.hohedoc.ir/status.php`: `secrets_present` both true, `ping` all three
   `ok: true`, `last_error: null`.
6. Turn off the Google trigger for `liliumPollDeploy`, so two runners never share one queue.

After any change under `files/`, run `bash deploy-host/selftest/selftest.sh` and
`python3 deploy-host/build-manifest.py`.
