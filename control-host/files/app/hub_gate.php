<?php
// Hub Gate Ticket Service for My-Control-Project (v72).
// Mints short-lived HMAC-SHA256 gate tickets so ONLY the Control App's built-in Hub browser
// can unlock the active Hub host (primary or standby, resolved dynamically from stylist-admin-state.json).
declare(strict_types=1);

const HUB_GATE_TTL_SEC = 120;

function hub_gate_secret(): string {
    if (defined('HUB_GATE_SECRET') && is_string(HUB_GATE_SECRET) && HUB_GATE_SECRET !== '') {
        return HUB_GATE_SECRET;
    }
    $vaultFile = dirname(__DIR__, 2) . '/holding-vault/hub-gate-secret.php';
    if (is_file($vaultFile)) {
        $v = @include $vaultFile;
        if (is_string($v) && $v !== '') return $v;
    }
    if (defined('DEVICE_KEYS') && is_array(DEVICE_KEYS) && !empty(DEVICE_KEYS['phone1'])) {
        return hash_hmac('sha256', 'hub-gate-v1', (string) DEVICE_KEYS['phone1']);
    }
    return '';
}

function hub_gate_mint_ticket(string $secret, string $device, string $role, int $ttlSec = HUB_GATE_TTL_SEC, ?int $now = null): array {
    $ts = $now ?? time();
    $exp = $ts + max(30, min(300, $ttlSec));
    $nonce = bin2hex(random_bytes(8));
    $safeRole = in_array($role, ['primary', 'standby'], true) ? $role : 'primary';
    $safeDev = preg_replace('/[^a-zA-Z0-9._-]/', '', $device) ?: 'phone1';
    $payload = "hg1.{$ts}.{$exp}.{$safeRole}.{$safeDev}.{$nonce}";
    $sig = hash_hmac('sha256', $payload, $secret);
    return [
        'ticket'     => $payload . '.' . $sig,
        'ts'         => $ts,
        'exp'        => $exp,
        'expires_in' => $exp - $ts,
    ];
}

function hub_gate_verify_ticket(string $secret, string $ticket, ?int $now = null): array {
    if ($secret === '' || $ticket === '') return ['ok' => false, 'error' => 'missing_ticket'];
    $parts = explode('.', trim($ticket));
    if (count($parts) !== 7 || $parts[0] !== 'hg1') return ['ok' => false, 'error' => 'bad_format'];
    [$ver, $tsS, $expS, $role, $dev, $nonce, $sig] = $parts;
    if (!ctype_digit($tsS) || !ctype_digit($expS)) return ['ok' => false, 'error' => 'bad_time'];
    $ts = (int) $tsS;
    $exp = (int) $expS;
    $t = $now ?? time();
    if ($t > $exp || $ts > $t + 60) return ['ok' => false, 'error' => 'expired'];
    $payload = "{$ver}.{$tsS}.{$expS}.{$role}.{$dev}.{$nonce}";
    $expected = hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $sig)) return ['ok' => false, 'error' => 'bad_sig'];
    return ['ok' => true, 'role' => $role, 'device' => $dev, 'exp' => $exp];
}

function hub_gate_probe_url(string $url): bool {
    if ($url === '' || !preg_match('#^https?://#i', $url)) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT      => 'control-hohedoc-hub-probe',
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 500;
}

function hub_gate_issue(array $in, string $device, ?array $stateOverride = null, ?callable $probeFn = null, ?string $secretOverride = null): array {
    $target = strtolower(trim((string) ($in['target'] ?? 'auto')));
    if (!in_array($target, ['auto', 'primary', 'standby'], true)) {
        return ['code' => 400, 'body' => ['ok' => false, 'error' => 'bad target']];
    }

    $state = $stateOverride ?? (function_exists('ctl_json_read') ? (ctl_json_read('stylist-admin-state.json', []) ?: []) : []);
    $hosts = is_array($state['hosts'] ?? null) ? $state['hosts'] : [];
    $primaryHub = trim((string) ($hosts['primary_hub'] ?? ''));
    $standbyHub = trim((string) ($hosts['standby_hub'] ?? ''));

    $probe = $probeFn ?? 'hub_gate_probe_url';
    $chosenRole = '';
    $chosenUrl = '';

    if ($target === 'primary') {
        $chosenRole = 'primary';
        $chosenUrl = $primaryHub;
    } elseif ($target === 'standby') {
        $chosenRole = 'standby';
        $chosenUrl = $standbyHub;
    } else {
        if ($primaryHub !== '' && $probe($primaryHub)) {
            $chosenRole = 'primary';
            $chosenUrl = $primaryHub;
        } elseif ($standbyHub !== '' && $probe($standbyHub)) {
            $chosenRole = 'standby';
            $chosenUrl = $standbyHub;
        } elseif ($primaryHub !== '') {
            $chosenRole = 'primary';
            $chosenUrl = $primaryHub;
        } elseif ($standbyHub !== '') {
            $chosenRole = 'standby';
            $chosenUrl = $standbyHub;
        }
    }

    if ($chosenUrl === '') {
        return ['code' => 503, 'body' => ['ok' => false, 'error' => 'hub host URL not published yet']];
    }

    $sec = $secretOverride ?? hub_gate_secret();
    if ($sec === '') {
        return ['code' => 500, 'body' => ['ok' => false, 'error' => 'hub gate secret unavailable']];
    }

    $minted = hub_gate_mint_ticket($sec, $device, $chosenRole, HUB_GATE_TTL_SEC);
    return [
        'code' => 200,
        'body' => [
            'ok'            => true,
            'url'           => $chosenUrl,
            'role'          => $chosenRole,
            'ticket'        => $minted['ticket'],
            'expires_in'    => $minted['expires_in'],
            'primary_hub'   => $primaryHub,
            'standby_hub'   => $standbyHub,
            'gate_enforced' => defined('HUB_GATE_ENFORCED') ? (bool) HUB_GATE_ENFORCED : false,
        ],
    ];
}
