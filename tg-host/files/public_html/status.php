<?php
// Minimal public health — JSON only (panel: ok / age / version; no secret names, no PHP version).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$root = dirname(__DIR__);
$d = @json_decode((string) @file_get_contents($root . '/var/deployed.json'), true) ?: [];
$tick = (int) @file_get_contents($root . '/var/last-tick.txt');
echo json_encode([
    'ok' => (bool) ($d['ok'] ?? false),
    'version' => $d['version'] ?? null,
    'ref_kind' => $d['ref_kind'] ?? null,
    'refused' => $d['refused'] ?? null,
    'deployed_age_s' => isset($d['at']) ? time() - strtotime($d['at']) : null,
    'tick_age_s' => $tick ? time() - $tick : null,
    'queued' => count(glob($root . '/var/queue/*.json') ?: []),
    'running' => count(glob($root . '/var/running/*.json') ?: []),
    // phase 7 (repo writer) — booleans and a code only: proof the host can write to the repo, no secret, no name
    'vault_ok' => (@json_decode((string) @file_get_contents($root . '/var/vault.json'), true)['state'] ?? '') === 'ok',
    'beat' => (function () use ($root) { $b = @json_decode((string) @file_get_contents($root . '/var/host-beat.json'), true);
        return is_array($b) ? ['ok' => (bool) ($b['ok'] ?? false), 'code' => (int) ($b['code'] ?? 0), 'age_s' => time() - (int) ($b['at'] ?? 0)] : null; })(),
], JSON_UNESCAPED_SLASHES);
