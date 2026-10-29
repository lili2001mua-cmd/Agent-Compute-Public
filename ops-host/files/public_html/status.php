<?php
// Public health endpoint (JSON): ages and booleans only, never a secret value.
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$s = ops_state();
$rows = [];
foreach (OPS_WATCH as $id => $h) {
    $at = (int) ($s[$id]['at'] ?? 0);
    $rows[$id] = ['max_h' => $h, 'age_h' => $at ? round((time() - $at) / 3600, 1) : null,
                  'stale' => (bool) ($s[$id]['stale'] ?? false)];
}
$lastTick = (int) @file_get_contents(ops_var('last-tick.txt'));
echo json_encode([
    'php' => PHP_VERSION,
    'deployed' => @json_decode((string) @file_get_contents(ops_var('deployed.json')), true) ?: null,
    'last_tick_age_s' => $lastTick ? time() - $lastTick : null,
    'bot_token_seen_by_cron' => @json_decode((string) @file_get_contents(ops_var('cli-bot.json')), true),
    'test_alert' => @json_decode((string) @file_get_contents(ops_var('test-alert.json')), true),
    'stranded' => (function () { $x = @json_decode((string) @file_get_contents(ops_var('stranded.json')), true); if (isset($x['result']['items'])) unset($x['result']['items']); return $x; })(),
    'gh_probe' => @json_decode((string) @file_get_contents(ops_var('gh-probe.json')), true),
    'branch_ops' => (function () { $x = @json_decode((string) @file_get_contents(ops_var('branch-ops.json')), true); if (isset($x['done'])) $x['done'] = array_slice($x['done'], -10, null, true); return $x; })(),
    'routing' => (function () { $x = @json_decode((string) @file_get_contents(ops_var('routing.json')), true); if (isset($x['result'])) unset($x['result']); return $x; })(),
    'drive_files' => @json_decode((string) @file_get_contents(ops_var('drive-files.json')), true),
    'routines' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
