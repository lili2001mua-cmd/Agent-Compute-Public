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
    // phase 9 diagnostics — booleans, codes and a short reason only
    'bot_poll' => (function () use ($root) { $b = @json_decode((string) @file_get_contents($root . '/var/bot-poll.json'), true);
        return is_array($b) ? ['age_s' => time() - (int) ($b['at'] ?? 0), 'ok' => (bool) ($b['ok'] ?? false), 'code' => (int) ($b['code'] ?? 0),
            'why' => (string) ($b['why'] ?? ''), 'last_cb_age_s' => empty($b['last_cb_at']) ? null : time() - (int) $b['last_cb_at']] : null; })(),
    'deploy' => (function () use ($root) { $p = @json_decode((string) @file_get_contents($root . '/var/deploy-pending.json'), true) ?: [];
        $c = @json_decode((string) @file_get_contents($root . '/var/deploy-last-callback.json'), true) ?: [];
        return ['pending' => !empty($p['nonce']) && (int) ($p['expires'] ?? 0) > time(), 'approved' => !empty($p['approved_sha']),
            'last_tap' => $c ? ['age_s' => time() - (int) ($c['at'] ?? 0), 'from_is_owner' => (bool) ($c['from_is_owner'] ?? false),
                'shape_ok' => (bool) ($c['shape_ok'] ?? false), 'answer' => (string) ($c['answer'] ?? '')] : null]; })(),
    'beat' => (function () use ($root) { $b = @json_decode((string) @file_get_contents($root . '/var/host-beat.json'), true);
        return is_array($b) ? ['ok' => (bool) ($b['ok'] ?? false), 'code' => (int) ($b['code'] ?? 0), 'age_s' => time() - (int) ($b['at'] ?? 0)] : null; })(),
], JSON_UNESCAPED_SLASHES);
