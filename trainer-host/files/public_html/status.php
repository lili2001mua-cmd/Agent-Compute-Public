<?php
// Public health endpoint (JSON, no UI): versions and booleans only, never a secret value.
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$deployed = @json_decode((string) @file_get_contents(TRAINER_ROOT . '/var/deployed.json'), true) ?: null;
$lastTick = (int) @file_get_contents(TRAINER_ROOT . '/var/last-tick.txt');
$present = [];
foreach (trainer_secrets() as $k => $v) $present[$k] = $v !== '';

echo json_encode([
    'php' => PHP_VERSION,
    'vault' => @json_decode((string) @file_get_contents(TRAINER_ROOT . '/var/vault.json'), true) ?: null,   // state + key names only
    'deployed' => $deployed,
    'last_tick_age_s' => $lastTick ? time() - $lastTick : null,
    'pool_watch' => (function () { $p = @json_decode((string) @file_get_contents(TRAINER_ROOT . '/var/pool-watch.json'), true);
        return is_array($p) ? ['adopted' => $p['adopted'] ?? null, 'seen' => count($p['seen'] ?? []), 'today' => $p['count'] ?? 0,
            'last_dispatch_age_s' => !empty($p['last']) ? time() - (int) $p['last'] : null,
            'error' => @file_get_contents(TRAINER_ROOT . '/var/pool-watch-error.txt') ?: null] : ['error' => @file_get_contents(TRAINER_ROOT . '/var/pool-watch-error.txt') ?: null]; })(),   // counts only, no ids
    'secrets_present' => $present,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
