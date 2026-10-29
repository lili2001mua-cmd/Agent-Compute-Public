<?php
// Public health endpoint (JSON): versions and booleans only, never a secret value or a queue argument.
require __DIR__ . '/../app/jobs.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$deployed = @json_decode((string) @file_get_contents(apps_var('deployed.json')), true) ?: null;
$lastTick = (int) @file_get_contents(apps_var('last-tick.txt'));
$present = [];
foreach (['ADMIN_KEY', 'DRIVE_OAUTH', 'DRIVE_ROOT_ID'] as $k) $present[$k] = apps_secret($k) !== '';
$states = [];
foreach (glob(queue_dir() . '/*.json') ?: [] as $p) { $s = json_decode((string) file_get_contents($p), true)['state'] ?? '?'; $states[$s] = ($states[$s] ?? 0) + 1; }
echo json_encode([
    'php' => PHP_VERSION,
    'deployed' => $deployed,
    'last_tick_age_s' => $lastTick ? time() - $lastTick : null,
    'secrets_present' => $present,
    'secrets_present_cron' => @json_decode((string) @file_get_contents(apps_var('cli-secrets.json')), true),
    'fileinfo' => class_exists('finfo'),
    'zip' => class_exists('ZipArchive'),
    'queue' => $states,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
