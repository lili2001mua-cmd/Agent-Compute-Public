<?php
// The team's sites. Adding a site = one entry here + its bridge secret in ../secrets.php. Nothing else.
// Public on purpose (this repo is public): repo names, domains and paths only — never a secret value.
//   repo     private repo that holds the site code, its queue and its results (same owner as the token)
//   queue    folder of request files written by a Claude session after the user's typed approval
//   results  folder the host writes one result file per request into
//   secret   name of the key in secrets.php that signs requests to this site's bridge (sync.php)
//   stamp    name of the key in secrets.php that checks the user's approval stamp in each request
//   envs     bridge URL per environment; a request may only name an env listed here
//   editbus  (optional) the chat edit bus: its folder id on the BACKUP Drive (My-Sites/<site>/editbus/); the token comes
//            from the studio host's BACKUP account (hohe_backup_drive), no key file on this host
//   prod     (optional) the production site this test site is compared with by the parity action
return [
    'stylistcrm' => [
        'repo' => 'stylistcrm',
        'queue' => 'deploy-queue',
        'results' => 'deploy-results/queue',
        'secret' => 'STYLISTCRM_DEPLOY_SECRET',
        'stamp' => 'STYLISTCRM_QUEUE_STAMP_KEY',
        'editbus' => ['folder' => '12hbRVCC44pKMegkkxzc1hdTlVUvIiF60'],   // My-Sites/stylistcrm/editbus/ on BACKUP
        'logical_db' => ['pwa' => 'redliliu_main'],                       // شناسهٔ منطقیِ دیتابیس (مستقل از آی‌پیِ هاست — STY-02 گامِ ۱۶۶)
        'mirrors' => [                                                    // هدفِ دومِ استقرار و مقابلهٔ هش (STY-05 گامِ ۱۱۸ · قابلِ جابه‌جاییِ تک‌خطی در STY-18)
            'pwa' => ['pwa-de'],
            'hub' => ['hub-de'],
        ],
        'envs' => [
            'pwa' => 'https://pwa.redlilium.ir/deploy/sync.php',
            'hub' => 'https://hub.redlilium.ir/deploy/sync.php',
            'help' => 'https://help.redlilium.ir/public/deploy/sync.php',
            'pwa-de' => 'https://pwa-de.hohedoc.ir/deploy/sync.php',
            'hub-de' => 'https://hub-de.hohedoc.ir/deploy/sync.php',
        ],
    ],
    // The team's site directory. Bridge URL wired for sites.hohedoc.ir (zero GitHub secrets);
    // stays 'not configured' in dh_ping() until STYLISTDIRECTORY_DEPLOY_SECRET is placed in secrets.php.
    'stylistdirectory' => [
        'repo' => 'stylistdirectory',
        'queue' => '',
        'results' => '',
        'secret' => 'STYLISTDIRECTORY_DEPLOY_SECRET',
        'envs' => [
            'final' => 'https://stylistdirectory.ir/deploy/sync.php',
        ],
    ],
    // Main site of liliumesorkh.ir (root domain, isolated from relay.liliumesorkh.ir).
    'liliumesorkh' => [
        'repo' => '',
        'queue' => '',
        'results' => '',
        'door_enabled' => true,
        'secret' => 'LILIUMESORKH_DEPLOY_SECRET',
        'envs' => [
            'main' => 'https://liliumesorkh.ir/deploy/sync.php',
        ],
    ],
];
