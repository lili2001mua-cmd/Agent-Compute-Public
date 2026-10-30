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

A request has one or more groups, each `{"action", "env", "files"}`:

| action | what the host does | replaces |
|---|---|---|
| `deploy` | sends the files (read at the request's commit) to the site | `lilium-deploy.yml` |
| `delete` | removes the files from the site | `lilium-deploy.yml` |
| `manifest` | pages through the site's file list into `deploy-results/manifest-<env>.txt` (`files: []`) | `lilium-manifest.yml` |
| `run` | opens one page on the env's own host (`files: ["path?query"]`, never a full URL); body into `deploy-results/run.txt` | `lilium-run.yml` |

A request runs only if it carries a valid `approval` stamp: `host_transfer_gate.py` adds it after
the user's typed approval, as an HMAC that only the gate and this host can make. An unstamped request
(for example one written from a chat without hooks) never runs; `status.php` lists it under
`waiting_for_approval`. The selftest stamps its request with the real gate code, so the two stay identical.

Scheduled read-only jobs (`files/app/jobs.php`, table `DH_JOBS`) run from the same tick:

| job | when (UTC) | replaces |
|---|---|---|
| `rum-digest` | Saturday 23:17 | `lilium-rum-digest.yml` — reads `storage/rum/<day>.jsonl` through the bridge, writes only aggregates to `speed-results/rum/digest-<from>_<to>.json` (byte-identical to `rum-digest.py`, cells under 20 samples hidden) |

Their last result is in `status.php` under `jobs`.

Every 30 minutes it also sends a read-only `ping` to every environment of every site. Any failure —
an expired token, a refused signature, a site that is down — lands in `status.php` as `last_error`.

## Secrets (`secrets.php` beside `deploy.php`, outside `public_html`, chmod 600)

```php
<?php return [
  'GITHUB_TOKEN'             => '<fine-grained token — see below>',
  'STYLISTCRM_DEPLOY_SECRET'   => '<the 'secret' value in deploy/config.php on the stylistcrm sites>',
  'STYLISTCRM_QUEUE_STAMP_KEY' => '<same value as LILIUM_QUEUE_STAMP_KEY in the Claude Code environment>',
  // two more lines per site, named by its 'secret' and 'stamp' fields in sites.php
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
