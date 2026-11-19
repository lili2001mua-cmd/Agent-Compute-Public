<?php
// Public health page of control.hohedoc.ir (app v64, owner decision 1405-08-28) — the app's home shows it next to
// ops.hohedoc.ir/status.php. Ages and booleans only: what is deployed, how old the last cron tick and the vault sync
// are. Never a path, a file name, a key or a token (builder: ctl_status_payload in app/lib.php).
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo json_encode(ctl_status_payload(
    @json_decode((string) @file_get_contents(ctl_var('deployed.json')), true) ?: null,
    ctl_json_read('last-tick.json', null),
    ctl_json_read('vault.json', null),
    ctl_secrets_loaded(),
    time()
), JSON_UNESCAPED_SLASHES);
