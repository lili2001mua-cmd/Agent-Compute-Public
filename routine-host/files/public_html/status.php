<?php
// Public health endpoint (JSON): ages, states and booleans only — never a secret, URL or token.
require_once __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$k = r_keys();
$keys = [];
foreach ($k['keys'] as $alias => $scopes) foreach ($scopes as $scope => $_) $keys["$alias-$scope"] = true;
$health = r_read_json('keys-health.json');
foreach ($health as $hk => $h) $health[$hk] = ['state' => $h['state'] ?? '?', 'last_code' => $h['last_code'] ?? null];
$runs = r_read_json('runs.json');
uasort($runs, fn($a, $b) => ($b['slot'] ?? 0) <=> ($a['slot'] ?? 0));
$jobs = [];
foreach ((r_jobs()['jobs'] ?? []) as $j) {
    $next = r_next_slot($j['cron_utc'], time());
    $jobs[$j['id']] = ['mode' => $j['mode'] ?? 'shadow', 'accounts' => $j['accounts'], 'class' => $j['account_class'],
                       'next_tehran' => $next ? r_tehran($next) : null];
}
$lastTick = (int) @file_get_contents(r_var('last-tick.txt'));
$probe = r_read_json('probe.json');
echo json_encode([
    'php' => PHP_VERSION,
    'deployed' => r_read_json('deployed.json') ?: null,
    'registry_sha' => r_jobs()['registry_sha'] ?? null,
    'last_tick_age_s' => $lastTick ? time() - $lastTick : null,
    'bot_token_seen_by_cron' => @json_decode((string) @file_get_contents(r_var('cli-bot.json')), true),
    'github_read_token_present' => r_secret('GITHUB_READ_TOKEN') !== '',
    'armed' => r_armed(),
    'halted' => r_read_json('halt.json')['halted'] ?? false,
    'host_country' => $probe['country'] ?? null,
    'keys_present' => $keys,
    'key_problems' => $k['problems'],
    'keys_health' => $health,
    'outbox_waiting' => count(r_read_json('outbox.json')),
    'jobs' => $jobs,
    'recent_runs' => array_slice($runs, 0, 20, true),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
