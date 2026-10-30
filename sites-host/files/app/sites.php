<?php
// The team's sites. Adding a site = one entry here + its bridge secret in ../secrets.php. Nothing else.
// Public on purpose (this repo is public): repo names, domains and paths only — never a secret value.
//   repo     private repo that holds the site code, its queue and its results (same owner as the token)
//   queue    folder of request files written by a Claude session after the user's typed approval
//   results  folder the host writes one result file per request into
//   secret   name of the key in secrets.php that signs requests to this site's bridge (sync.php)
//   stamp    name of the key in secrets.php that checks the user's approval stamp in each request
//   envs     bridge URL per environment; a request may only name an env listed here
//   prod     (optional) the production site this test site is compared with by the parity action
return [
    'stylistcrm' => [
        'repo' => 'stylistcrm',
        'queue' => 'deploy-queue',
        'results' => 'deploy-results/queue',
        'secret' => 'STYLISTCRM_DEPLOY_SECRET',
        'stamp' => 'STYLISTCRM_QUEUE_STAMP_KEY',
        'prod' => 'stylistcrm-prod',
        'envs' => [
            'pwa' => 'https://pwa.redlilium.ir/deploy/sync.php',
            'hub' => 'https://hub.redlilium.ir/deploy/sync.php',
            'help' => 'https://help.redlilium.ir/public/deploy/sync.php',
        ],
    ],
    // Production copy of the same app; only read by parity (no queue of its own, never deployed to from here).
    'stylistcrm-prod' => [
        'repo' => 'stylistcrm',
        'queue' => '',
        'results' => '',
        'secret' => 'STYLISTCRM_PROD_DEPLOY_SECRET',
        'envs' => [
            'pwa' => 'https://pwa.stylistcrm.ir/deploy/sync.php',
            'hub' => 'https://hub.stylistcrm.ir/deploy/sync.php',
            'help' => 'https://help.stylistcrm.ir/public/deploy/sync.php',
        ],
    ],
];
