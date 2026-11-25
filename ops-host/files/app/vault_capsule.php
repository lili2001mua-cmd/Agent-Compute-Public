<?php
// vault_capsule.php — Builds the Germany-host metadata manifest and master-passphrase encrypted HBV1 capsule
// (Holding-Backup-Vault/v1/02-germany-host/) from CLI cron (app/tick.php).
// Zero external binaries (pure PHP USTAR tar + gzencode + PBKDF2-HMAC-SHA256 600k + AES-256-CBC + HMAC-SHA256).
// Byte-for-byte compatible with Agent-Project-Backup/Sys.scripts/holding_vault_crypto.py.
// Never prints or logs any secret value.
require_once __DIR__ . '/lib.php';

const HBV1_MAGIC = 'HBV1';
const HBV1_FLAG_TAR_GZ = 1;
const HBV1_PBKDF2_ITERS = 600000;
const OPS_SUBDOMAINS = [
    'ops.hohedoc.ir', 'routine.hohedoc.ir', 'sites.hohedoc.ir', 'apps.hohedoc.ir',
    'studio.hohedoc.ir', 'tg.hohedoc.ir', 'trainer.hohedoc.ir', 'deploy.hohedoc.ir',
    'control.hohedoc.ir',
];
const OPS_SECRET_FILES = [
    'secrets.php', 'drive-sa.json', 'drive-oauth.env', 'drive-web-client.json',
    'var/deployed.json', 'var/drive-web-client.json',
];

/** Pure-PHP POSIX USTAR tar builder (no exec, no Phar dependency) -> gzip bytes. */
function hbv1_pack_tar_gz(array $entries): string {
    $tar = '';
    $now = time();
    ksort($entries);
    foreach ($entries as $relPath => $data) {
        $relPath = ltrim(str_replace('\\', '/', (string) $relPath), '/');
        if ($relPath === '' || str_contains($relPath, '..')) continue;
        $prefix = '';
        $name = $relPath;
        if (strlen($name) > 100) {
            $pos = strrpos(substr($name, 0, 155), '/');
            if ($pos === false) continue;
            $prefix = substr($name, 0, $pos);
            $name = substr($name, $pos + 1);
            if (strlen($name) > 100) continue;
        }
        $size = strlen($data);
        $hdr = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $name,
            sprintf('%07o', 0600),
            sprintf('%07o', 0),
            sprintf('%07o', 0),
            sprintf('%011o', $size),
            sprintf('%011o', $now),
            '        ',
            '0',
            '',
            'ustar',
            '00',
            'root',
            'root',
            sprintf('%07o', 0),
            sprintf('%07o', 0),
            $prefix,
            ''
        );
        $sum = 0;
        for ($i = 0; $i < 512; $i++) $sum += ord($hdr[$i]);
        $hdr = substr($hdr, 0, 148) . sprintf("%06o\0 ", $sum) . substr($hdr, 156);
        $tar .= $hdr . $data;
        $rem = $size % 512;
        if ($rem !== 0) $tar .= str_repeat("\0", 512 - $rem);
    }
    $tar .= str_repeat("\0", 1024);
    $gz = gzencode($tar, 6);
    if (!is_string($gz)) throw new RuntimeException('gzencode failed');
    return $gz;
}

/** Seal tar.gz bytes into HBV1 format matching holding_vault_crypto.py byte-for-byte. */
function hbv1_seal_bytes(string $tarGzBytes, string $passphrase): string {
    $passphrase = trim($passphrase);
    if (strlen($passphrase) < 12) throw new RuntimeException('Master passphrase must be >= 12 bytes');
    $salt = random_bytes(16);
    $iv = random_bytes(16);
    $dk = hash_pbkdf2('sha256', $passphrase, $salt, HBV1_PBKDF2_ITERS, 64, true);
    $encKey = substr($dk, 0, 32);
    $macKey = substr($dk, 32, 32);
    $ciphertext = openssl_encrypt($tarGzBytes, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);
    if (!is_string($ciphertext)) throw new RuntimeException('openssl_encrypt failed');
    $header = HBV1_MAGIC . chr(HBV1_FLAG_TAR_GZ) . $salt . $iv;
    $mac = hash_hmac('sha256', $header . $ciphertext, $macKey, true);
    return $header . $ciphertext . $mac;
}

function ops_vault_base_dirs(): array {
    $root = realpath(OPS_ROOT) ?: OPS_ROOT;
    $domainsDir = dirname($root);       // e.g. .../domains
    $homeDir = dirname($domainsDir);    // e.g. /home/<user>
    $vaultDir = null;
    foreach ([$homeDir . '/holding-vault', $domainsDir . '/holding-vault'] as $cand) {
        if (@is_dir($cand)) { $vaultDir = $cand; break; }
    }
    return [$domainsDir, $vaultDir];
}

function ops_vault_master_passphrase(?string $vaultDir): string {
    if ($vaultDir && @is_file($vaultDir . '/master-passphrase.php')) {
        $p = @include $vaultDir . '/master-passphrase.php';
        if (is_string($p) && strlen(trim($p)) >= 12) return trim($p);
    }
    $s = ops_secret('HOLDING_VAULT_PASSPHRASE');
    return strlen(trim($s)) >= 12 ? trim($s) : '';
}

function ops_vault_capsule_collect(): array {
    [$domainsDir, $vaultDir] = ops_vault_base_dirs();
    $entries = [];
    $metaFiles = [];

    if ($vaultDir !== null) {
        foreach (glob($vaultDir . '/*') ?: [] as $p) {
            if (!@is_file($p) || basename($p) === 'master-passphrase.php') continue;
            $raw = @file_get_contents($p);
            if (!is_string($raw)) continue;
            $rel = 'germany-host/holding-vault/' . basename($p);
            $entries[$rel] = $raw;
            $metaFiles[] = ['path' => $rel, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw), 'mtime' => filemtime($p)];
        }
    }

    foreach (OPS_SUBDOMAINS as $sub) {
        $subDir = $domainsDir . '/' . $sub;
        if (!@is_dir($subDir)) continue;
        $candidates = [];
        foreach (OPS_SECRET_FILES as $sf) $candidates[] = $subDir . '/' . $sf;
        foreach (glob($subDir . '/secrets-extra*.php') ?: [] as $ex) $candidates[] = $ex;
        foreach (array_unique($candidates) as $p) {
            if (!@is_file($p)) continue;
            $raw = @file_get_contents($p);
            if (!is_string($raw)) continue;
            $rel = 'germany-host/domains/' . $sub . '/' . ltrim(substr($p, strlen($subDir)), '/');
            $entries[$rel] = $raw;
            $metaFiles[] = ['path' => $rel, 'bytes' => strlen($raw), 'sha256' => hash('sha256', $raw), 'mtime' => filemtime($p)];
        }
    }

    usort($metaFiles, fn($a, $b) => strcmp($a['path'], $b['path']));
    $inputHash = hash('sha256', json_encode(array_map(fn($f) => [$f['path'], $f['sha256']], $metaFiles)));
    return [$entries, $metaFiles, $inputHash, $vaultDir];
}

function ops_vault_capsule_tick(): void {
    if (PHP_SAPI !== 'cli') return;
    $dir = ops_var('vault-capsule');
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $mfPath = $dir . '/manifest.json';
    $capPath = $dir . '/latest.hbv1';
    $prev = @json_decode((string) @file_get_contents($mfPath), true) ?: [];

    // Throttle to at most once every 6 hours unless missing
    if (!empty($prev['checked_at']) && (time() - (int) $prev['checked_at']) < 21600 && is_file($mfPath)) return;

    [$entries, $metaFiles, $inputHash, $vaultDir] = ops_vault_capsule_collect();
    $pass = ops_vault_master_passphrase($vaultDir);

    $manifest = [
        'schema' => 'holding-backup-vault/v1/germany-host',
        'crypto_format' => 'HBV1',
        'checked_at' => time(),
        'updated_utc' => gmdate('c'),
        'files_count' => count($metaFiles),
        'input_hash' => $inputHash,
        'files' => $metaFiles,
        'capsule_ready' => false,
        'capsule_bytes' => 0,
        'capsule_sha256' => null,
        'reason' => null,
    ];

    if ($pass === '') {
        $manifest['reason'] = 'master_passphrase_not_set';
    } elseif (($prev['input_hash'] ?? '') === $inputHash && !empty($prev['capsule_ready']) && is_file($capPath)) {
        $manifest['capsule_ready'] = true;
        $manifest['capsule_bytes'] = filesize($capPath);
        $manifest['capsule_sha256'] = $prev['capsule_sha256'] ?? hash_file('sha256', $capPath);
        $manifest['updated_utc'] = $prev['updated_utc'] ?? gmdate('c');
    } else {
        $tarGz = hbv1_pack_tar_gz($entries);
        $sealed = hbv1_seal_bytes($tarGz, $pass);
        file_put_contents($capPath . '.tmp', $sealed, LOCK_EX);
        @chmod($capPath . '.tmp', 0600);
        rename($capPath . '.tmp', $capPath);
        $manifest['capsule_ready'] = true;
        $manifest['capsule_bytes'] = strlen($sealed);
        $manifest['capsule_sha256'] = hash('sha256', $sealed);
        ops_log('vault-capsule sealed files=' . count($metaFiles) . ' bytes=' . strlen($sealed));
    }

    file_put_contents($mfPath . '.tmp', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($mfPath . '.tmp', 0600);
    rename($mfPath . '.tmp', $mfPath);
}
