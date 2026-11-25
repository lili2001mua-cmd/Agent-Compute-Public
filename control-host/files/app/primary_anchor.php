<?php
// Single primary hardware anchor lock for control.hohedoc.ir (holding-backup BKP-05 Step 183).
// Rule (user decision 1405-09-03): exactly ONE personal device holds Holding-Backup-Vault/v1/.
// All backup download/sync doors check anchor_require_primary($callerDevice) and refuse non-primary devices (HTTP 403).
// Zero secrets are stored here — only device_id, user label, vault folder name, timestamps, and transfer history.
require_once __DIR__ . '/lib.php';

const ANCHOR_STATE_FILE = 'primary-anchor.json';
const ANCHOR_CHALLENGE_FILE = 'anchor-challenges.json';
const ANCHOR_DEFAULT_VAULT_DIR = 'Holding-Backup-Vault/v1';

function anchor_read(): ?array {
    $d = ctl_json_read(ANCHOR_STATE_FILE, null);
    if (!is_array($d) || empty($d['device_id'])) return null;
    return $d;
}

function anchor_sanitize_label(string $label): string {
    $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
    if (mb_strlen($label) > 80) $label = mb_substr($label, 0, 80);
    return $label;
}

function anchor_sanitize_vault_dir(string $dir): string {
    $dir = trim($dir);
    if ($dir === '') return ANCHOR_DEFAULT_VAULT_DIR;
    if (strlen($dir) > 120 || str_contains($dir, '..') || str_contains($dir, "\0")) return '';
    if (!preg_match('#^[A-Za-z0-9._/\- ]+$#', $dir)) return '';
    return trim($dir, '/');
}

/** Public-safe status payload for GET api.php?op=anchor-status. */
function anchor_status(string $callerDevice): array {
    $cur = anchor_read();
    if ($cur === null) {
        return [
            'ok' => true,
            'has_anchor' => false,
            'is_caller_primary' => false,
            'caller_device' => $callerDevice,
            'anchor' => null,
        ];
    }
    return [
        'ok' => true,
        'has_anchor' => true,
        'is_caller_primary' => hash_equals((string) $cur['device_id'], $callerDevice),
        'caller_device' => $callerDevice,
        'anchor' => [
            'device_id' => (string) $cur['device_id'],
            'label' => (string) ($cur['label'] ?? ''),
            'vault_dir' => (string) ($cur['vault_dir'] ?? ANCHOR_DEFAULT_VAULT_DIR),
            'registered_at' => (int) ($cur['registered_at'] ?? 0),
            'updated_at' => (int) ($cur['updated_at'] ?? 0),
            'transfers_count' => count(is_array($cur['history'] ?? null) ? $cur['history'] : []),
        ],
    ];
}

/** Gate used by backup manifest & chunk streaming endpoints: returns null if allowed, else error string. */
function anchor_require_primary(string $callerDevice): ?string {
    $cur = anchor_read();
    if ($cur === null) return 'no primary hardware anchor registered yet';
    if (!hash_equals((string) $cur['device_id'], $callerDevice)) {
        return 'device is not the registered primary hardware anchor';
    }
    return null;
}

/** POST api.php?op=anchor-register: register first primary anchor or update metadata if caller is already primary. */
function anchor_register(string $callerDevice, array $in, ?int $now = null): array {
    $now = $now ?? time();
    $label = anchor_sanitize_label((string) ($in['label'] ?? ''));
    if (mb_strlen($label) < 2) {
        return [400, ['ok' => false, 'error' => 'label 2-80 chars required']];
    }
    $vaultDir = anchor_sanitize_vault_dir((string) ($in['vault_dir'] ?? ANCHOR_DEFAULT_VAULT_DIR));
    if ($vaultDir === '') {
        return [400, ['ok' => false, 'error' => 'invalid vault_dir']];
    }

    $cur = anchor_read();
    if ($cur !== null && !hash_equals((string) $cur['device_id'], $callerDevice)) {
        return [409, [
            'ok' => false,
            'error' => 'another device is already the primary hardware anchor — use anchor-transfer-request',
            'current_device_id' => (string) $cur['device_id'],
            'current_label' => (string) ($cur['label'] ?? ''),
        ]];
    }

    $record = [
        'device_id' => $callerDevice,
        'label' => $label,
        'vault_dir' => $vaultDir,
        'registered_at' => $cur ? (int) ($cur['registered_at'] ?? $now) : $now,
        'updated_at' => $now,
        'history' => is_array($cur['history'] ?? null) ? $cur['history'] : [],
    ];
    ctl_json_write(ANCHOR_STATE_FILE, $record);
    ctl_log("anchor-register device=$callerDevice");
    return [200, anchor_status($callerDevice)];
}

/** POST api.php?op=anchor-transfer-request: step 1 of moving primary anchor to a replacement device. */
function anchor_transfer_request(string $callerDevice, array $in, ?int $now = null): array {
    $now = $now ?? time();
    $cur = anchor_read();
    if ($cur === null) {
        return [409, ['ok' => false, 'error' => 'no existing anchor — use anchor-register directly']];
    }
    if (hash_equals((string) $cur['device_id'], $callerDevice)) {
        return [409, ['ok' => false, 'error' => 'caller is already the primary hardware anchor']];
    }

    $label = anchor_sanitize_label((string) ($in['label'] ?? ''));
    $reason = trim((string) ($in['reason'] ?? ''));
    $vaultDir = anchor_sanitize_vault_dir((string) ($in['vault_dir'] ?? ANCHOR_DEFAULT_VAULT_DIR));
    if (mb_strlen($label) < 2) return [400, ['ok' => false, 'error' => 'label 2-80 chars required']];
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) return [400, ['ok' => false, 'error' => 'reason 3-200 chars required']];
    if ($vaultDir === '') return [400, ['ok' => false, 'error' => 'invalid vault_dir']];

    $id = bin2hex(random_bytes(12));
    $ch = ctl_json_read(ANCHOR_CHALLENGE_FILE, []);
    foreach ($ch as $k => $c) {
        if ((int) ($c['exp'] ?? 0) < $now) unset($ch[$k]);
    }
    $ch[$id] = [
        'new_device' => $callerDevice,
        'old_device' => (string) $cur['device_id'],
        'label' => $label,
        'vault_dir' => $vaultDir,
        'reason' => $reason,
        'exp' => $now + CTL_CHALLENGE_TTL,
    ];
    ctl_json_write(ANCHOR_CHALLENGE_FILE, $ch);
    ctl_log("anchor-transfer-request from={$cur['device_id']} to=$callerDevice");
    return [200, [
        'ok' => true,
        'challenge' => $id,
        'expires_in' => CTL_CHALLENGE_TTL,
        'summary' => [
            'from_device' => (string) $cur['device_id'],
            'from_label' => (string) ($cur['label'] ?? ''),
            'to_device' => $callerDevice,
            'to_label' => $label,
            'reason' => $reason,
        ],
    ]];
}

/** POST api.php?op=anchor-transfer-confirm: step 2 — atomically switch single primary anchor to new device. */
function anchor_transfer_confirm(string $callerDevice, array $in, ?int $now = null): array {
    $now = $now ?? time();
    $id = trim((string) ($in['challenge'] ?? ''));
    if ($id === '') return [400, ['ok' => false, 'error' => 'challenge required']];

    $ch = ctl_json_read(ANCHOR_CHALLENGE_FILE, []);
    $c = $ch[$id] ?? null;
    unset($ch[$id]);
    ctl_json_write(ANCHOR_CHALLENGE_FILE, $ch);

    if (!is_array($c) || (int) ($c['exp'] ?? 0) < $now || !hash_equals((string) ($c['new_device'] ?? ''), $callerDevice)) {
        return [410, ['ok' => false, 'error' => 'challenge expired or unknown']];
    }

    $cur = anchor_read();
    $history = is_array($cur['history'] ?? null) ? $cur['history'] : [];
    if ($cur !== null) {
        $history[] = [
            'device_id' => (string) $cur['device_id'],
            'label' => (string) ($cur['label'] ?? ''),
            'replaced_at' => $now,
            'replaced_by' => $callerDevice,
            'reason' => (string) ($c['reason'] ?? ''),
        ];
        if (count($history) > 20) $history = array_slice($history, -20);
    }

    $record = [
        'device_id' => $callerDevice,
        'label' => (string) ($c['label'] ?? ''),
        'vault_dir' => (string) ($c['vault_dir'] ?? ANCHOR_DEFAULT_VAULT_DIR),
        'registered_at' => $now,
        'updated_at' => $now,
        'history' => $history,
    ];
    ctl_json_write(ANCHOR_STATE_FILE, $record);
    ctl_log("anchor-transfer-confirm from={$c['old_device']} to=$callerDevice");
    return [200, anchor_status($callerDevice)];
}
