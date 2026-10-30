<?php
// Public health endpoint (JSON): versions, booleans and status codes only — never a secret, a body
// or a queue argument. This is how the user and Claude see that the channel is alive.
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$st = dh_state();
$present = [];
foreach (array_merge(['GITHUB_TOKEN'], array_column(dh_sites(), 'secret'), array_column(dh_sites(), 'stamp')) as $k) $present[$k] = trim((string) (dh_secrets()[$k] ?? '')) !== '';
$lastTick = (int) @file_get_contents(dh_var('last-tick.txt'));
echo json_encode([
    'php' => PHP_VERSION,
    'deployed' => @json_decode((string) @file_get_contents(dh_var('deployed.json')), true) ?: null,
    'last_tick_age_s' => $lastTick ? time() - $lastTick : null,
    'secrets_present' => $present,
    'ping' => $st['ping'] ?? null,
    'last_ok' => $st['last_ok'] ?? null,
    'last_error' => $st['last_error'] ?? null,
    'last_run' => $st['last_run'] ?? null,
    'waiting_for_approval' => $st['waiting_for_approval'] ?? [],
    'requests_seen' => count($st['done'] ?? []),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
