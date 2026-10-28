<?php
// Minimal public health (panel: ok / age / version only — no secret names, no PHP version).
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
], JSON_UNESCAPED_SLASHES);
