# sites-host — sites.hohedoc.ir

One door between the team's sites and GitHub, with zero GitHub Actions minutes however often the
sites change. Most sites are hosted inside Iran, which the Claude environment cannot reach; this
host (Germany) can. Adding a site = one entry in `files/app/sites.php` + its bridge secret in
`secrets.php`. No new workflow, no new subdomain.

It replaces the Google Apps Script channel (`stylistcrm/Sys.scripts/apps-script/lilium-deploy.gs`),
which stopped after 1405-07-20 without any visible error. `lilium-deploy.yml` stays as the fallback.

The user's approval stays in the chat: a session writes a queue file only after
«تأیید انتقال به هاست». This host only executes what is already on `main`.

## How it works

Cron runs `app/tick.php` every minute. For every site it lists the queue, skips any request that
already has a result file (so requests the Google channel or Actions already ran are never
repeated), reads each new request at the commit that wrote it, signs the body like
`lilium-call.py`, POSTs it to the site's `sync.php`, and writes the result back to `main`.
Each request runs once. One broken site does not stop the others.

Every 30 minutes it also sends a read-only `ping` to every environment of every site. Any failure —
an expired token, a refused signature, a site that is down — lands in `status.php` as `last_error`.

## Secrets (`secrets.php` beside `deploy.php`, outside `public_html`, chmod 600)

```php
<?php return [
  'GITHUB_TOKEN'             => '<fine-grained token — see below>',
  'STYLISTCRM_DEPLOY_SECRET' => '<same value as deploy/config.php on the stylistcrm sites>',
  // one more line per site, named by its 'secret' field in sites.php
];
```

The token is fine-grained, "All repositories" of the holding's account, with only
`Contents: Read and write` (Metadata read is implied). New site repos are covered without a new
token. It has no Actions, Admin, Secrets or Workflows permission.

## Install (once)

1. Create subdomain `sites.hohedoc.ir` with SSL in DirectAdmin.
2. Upload `bootstrap/deploy.php` and `secrets.php` to `domains/sites.hohedoc.ir/`.
3. Cron every 5 min: `/usr/local/bin/php /home/<user>/domains/sites.hohedoc.ir/deploy.php`
4. Cron every 1 min: `/usr/local/bin/php /home/<user>/domains/sites.hohedoc.ir/app/tick.php`
5. Open `https://sites.hohedoc.ir/status.php`: every secret `true`, every `ping` `ok: true`,
   `last_error: null`.
6. Turn off the Google trigger for `liliumPollDeploy`, so two runners never share one queue.

After any change under `files/`, run `bash sites-host/selftest/selftest.sh` and
`python3 sites-host/build-manifest.py`.
