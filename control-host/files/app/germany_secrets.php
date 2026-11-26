<?php
// germany_secrets.php — Live inventory & compliance audit of all secrets on the Germany host (hohedoc.ir).
// Security contract:
//   - NEVER reads or returns any secret VALUE; extracts key/constant NAMES and file metadata only.
//   - Actively verifies that no secret files sit in web-accessible public_html/ or outside Germany subdomains.
//   - Surfaces the encrypted HBV1 capsule backup status from ops.hohedoc.ir/var/vault-capsule/manifest.json.
require_once __DIR__ . '/lib.php';

/** Extract constant / define / array key NAMES from a PHP secrets file without executing or leaking values. */
function gs_extract_php_key_names(string $code): array {
    $names = [];
    if (preg_match_all('/\bconst\s+([A-Z][A-Z0-9_]{2,})\s*=/', $code, $m)) {
        foreach ($m[1] as $k) $names[$k] = true;
    }
    if (preg_match_all('/\bdefine\s*\(\s*[\'"]([A-Z][A-Z0-9_]{2,})[\'"]\s*,/', $code, $m)) {
        foreach ($m[1] as $k) $names[$k] = true;
    }
    if (preg_match_all('/[\'"]([A-Z][A-Z0-9_]{2,})[\'"]\s*=>/', $code, $m)) {
        foreach ($m[1] as $k) $names[$k] = true;
    }
    $out = array_keys($names);
    sort($out);
    return $out;
}

/** Extract key names from a .env file (KEY=...) without reading values. */
function gs_extract_env_key_names(string $text): array {
    $names = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (preg_match('/^([A-Z][A-Z0-9_]{2,})\s*=/', $line, $m)) {
            $names[$m[1]] = true;
        }
    }
    $out = array_keys($names);
    sort($out);
    return $out;
}

/** Scan all Germany host subdomains and holding-vault, returning per-subdomain secret key inventory + compliance check. */
function gs_scan_germany(?string $domainsDir = null, ?string $homeDir = null): array {
    $root = realpath(CTL_ROOT) ?: CTL_ROOT;
    $domainsDir = $domainsDir ?? dirname($root);
    $homeDir    = $homeDir ?? dirname($domainsDir);

    $groups = [];
    $alerts = [];
    $totalKeys = 0;
    $totalFiles = 0;

    // 1) Central holding-vault (if present under home/ or domains/)
    foreach ([$homeDir . '/holding-vault', $domainsDir . '/holding-vault'] as $cand) {
        if (!@is_dir($cand)) continue;
        $files = [];
        $keys = [];
        foreach (glob($cand . '/*') ?: [] as $p) {
            if (!@is_file($p)) continue;
            $bn = basename($p);
            $files[] = ['file' => $bn, 'bytes' => (int) @filesize($p)];
            $keys[] = $bn;
        }
        sort($keys);
        if ($files) {
            $groups[] = [
                'subdomain'   => 'holding-vault (مرکزیِ آلمان)',
                'files_count' => count($files),
                'keys_count'  => count($keys),
                'keys'        => $keys,
                'files'       => array_column($files, 'file'),
            ];
            $totalFiles += count($files);
            $totalKeys += count($keys);
        }
        break;
    }

    // 2) Every Germany subdomain under domains/*.hohedoc.ir
    $subDirs = glob($domainsDir . '/*.hohedoc.ir', GLOB_ONLYDIR) ?: [];
    sort($subDirs);
    foreach ($subDirs as $subDir) {
        $sub = basename($subDir);
        $files = [];
        $keysMap = [];

        // Standard secret PHP files
        $phpCandidates = [$subDir . '/secrets.php', $subDir . '/var/gh-token.php'];
        foreach (glob($subDir . '/secrets-extra*.php') ?: [] as $ex) $phpCandidates[] = $ex;
        foreach (array_unique($phpCandidates) as $p) {
            if (!@is_file($p)) continue;
            $rel = ltrim(substr($p, strlen($subDir)), '/');
            $files[] = $rel;
            $raw = (string) @file_get_contents($p);
            $found = gs_extract_php_key_names($raw);
            if ($found) {
                foreach ($found as $k) $keysMap[$k] = true;
            } else {
                $keysMap[$rel] = true;
            }
        }

        // JSON / env / key files
        foreach (['drive-sa.json', 'drive-oauth.env', 'drive-web-client.json', 'var/drive-web-client.json', 'var/hub-gate.key'] as $sf) {
            $p = $subDir . '/' . $sf;
            if (!@is_file($p)) continue;
            $files[] = $sf;
            if (str_ends_with($sf, '.env')) {
                foreach (gs_extract_env_key_names((string) @file_get_contents($p)) as $k) $keysMap[$k] = true;
            } else {
                $keysMap[$sf] = true;
            }
        }

        // Encrypted AI vault entries (control.hohedoc.ir/var/ai-vault/*.enc)
        foreach (glob($subDir . '/var/ai-vault/*.enc') ?: [] as $enc) {
            if (!@is_file($enc)) continue;
            $bn = basename($enc, '.enc');
            $files[] = 'var/ai-vault/' . basename($enc);
            $keysMap['ai-vault:' . $bn] = true;
        }

        // Connected Drive OAuth accounts (studio.hohedoc.ir/var/drive-accounts/*.json)
        foreach (glob($subDir . '/var/drive-accounts/*.json') ?: [] as $da) {
            if (!@is_file($da)) continue;
            $bn = basename($da, '.json');
            $files[] = 'var/drive-accounts/' . basename($da);
            $keysMap['drive-account:' . $bn] = true;
        }

        // Compliance guard: NO secret file may live inside web-accessible public_html/
        foreach (['public_html/secrets.php', 'public_html/.env', 'public_html/drive-sa.json'] as $bad) {
            if (@is_file($subDir . '/' . $bad)) {
                $alerts[] = "فایلِ حساس در مسیرِ عمومیِ وب پیدا شد: {$sub}/{$bad}";
            }
        }

        if ($files) {
            $keys = array_keys($keysMap);
            sort($keys);
            $groups[] = [
                'subdomain'   => $sub,
                'files_count' => count($files),
                'keys_count'  => count($keys),
                'keys'        => $keys,
                'files'       => $files,
            ];
            $totalFiles += count($files);
            $totalKeys += count($keys);
        }
    }

    // Compliance guard: check no non-Germany domain directory under domains/ holds secrets.php
    foreach (glob($domainsDir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $name = basename($d);
        if (str_ends_with($name, '.hohedoc.ir') || $name === 'holding-vault') continue;
        if (@is_file($d . '/secrets.php')) {
            $alerts[] = "فایلِ secrets.php خارج از زیرشاخه‌های آلمان پیدا شد: {$name}/secrets.php";
        }
    }

    // Capsule backup status from ops.hohedoc.ir/var/vault-capsule/manifest.json
    $capMan = @json_decode((string) @file_get_contents($domainsDir . '/ops.hohedoc.ir/var/vault-capsule/manifest.json'), true);
    $capsule = [
        'ready'       => is_array($capMan) && !empty($capMan['capsule_ready']),
        'bytes'       => is_array($capMan) ? (int) ($capMan['capsule_bytes'] ?? 0) : 0,
        'sha256'      => is_array($capMan) ? (string) ($capMan['capsule_sha256'] ?? '') : '',
        'files_count' => is_array($capMan) ? (int) ($capMan['files_count'] ?? 0) : 0,
        'updated_utc' => is_array($capMan) ? (string) ($capMan['updated_utc'] ?? '') : '',
        'reason'      => is_array($capMan) ? ($capMan['reason'] ?? null) : 'manifest_missing',
    ];

    return [
        'ok'                    => true,
        'checked_at'            => time(),
        'source'                => PHP_SAPI === 'cli' ? 'cli-cron' : 'web-fallback',
        'all_only_in_germany'   => count($alerts) === 0,
        'github_secrets_policy' => 'zero-github-secrets',
        'alerts'                => $alerts,
        'subdomains_count'      => count($groups),
        'total_files'           => $totalFiles,
        'total_keys'            => $totalKeys,
        'subdomains'            => $groups,
        'capsule'               => $capsule,
    ];
}

/** CLI cron entry (tick.php): runs without web open_basedir so every Germany subdomain (*.hohedoc.ir + holding-vault) is scanned. */
function germany_secrets_tick(): void {
    if (PHP_SAPI !== 'cli') return;
    $lock = @fopen(ctl_var('germany-secrets.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    $res = gs_scan_germany();
    ctl_json_write('germany-secrets.json', $res);
    flock($lock, LOCK_UN);
}
