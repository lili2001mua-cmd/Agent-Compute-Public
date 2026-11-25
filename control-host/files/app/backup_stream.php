<?php
// Unified 4-source backup manifest & 8 MiB resumable chunk streaming for control.hohedoc.ir
// (holding-backup BKP-05 Step 184 — strictly gated by primary_anchor.php single-device lock).
// Sources: github | iran | germany | drive.
// Zero secrets are ever returned; non-primary devices receive HTTP 403.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/primary_anchor.php';

const BACKUP_MAX_CHUNK_BYTES = 8388608;   // 8 MiB exact
const BACKUP_SOURCES = ['github' => 1, 'iran' => 1, 'germany' => 1, 'drive' => 1];
const BACKUP_CATALOG_FILE = 'backup-catalog.json';
const BACKUP_CATALOG_PERIOD = 900;        // refresh every 15 min in cron

function backup_stage_dir(string $source, ?string $baseDirOverride = null): string {
    $root = $baseDirOverride !== null ? rtrim($baseDirOverride, '/') : ctl_var('backup-stage');
    $dir = $root . '/' . $source;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function backup_artifact_ok(string $name): bool {
    if ($name === '' || strlen($name) > 120) return false;
    if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) return false;
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/', $name);
}

/** Resolve an allowed backup artifact path for a given source (never leaves source directory). */
function backup_resolve_artifact(string $source, string $artifact, ?string $baseDirOverride = null): ?string {
    if (!isset(BACKUP_SOURCES[$source]) || !backup_artifact_ok($artifact)) return null;
    $stageFile = backup_stage_dir($source, $baseDirOverride) . '/' . $artifact;
    if (is_file($stageFile) && is_readable($stageFile)) return $stageFile;

    // Sibling Germany-host vault capsule (ops.hohedoc.ir/var/vault-capsule/) when running on live host
    if ($source === 'germany' && $baseDirOverride === null) {
        $domains = dirname(realpath(CTL_ROOT) ?: CTL_ROOT);
        $opsFile = $domains . '/ops.hohedoc.ir/var/vault-capsule/' . $artifact;
        if (@is_file($opsFile) && @is_readable($opsFile)) return $opsFile;
    }
    return null;
}

/** Cron helper (CLI): aggregate metadata from all 4 sources into var/backup-catalog.json. */
function backup_catalog_tick(bool $force = false): void {
    if (PHP_SAPI !== 'cli') return;
    $lk = @fopen(ctl_var('backup-catalog.lock'), 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return;
    try {
        $cur = ctl_json_read(BACKUP_CATALOG_FILE, null);
        if (!$force && is_array($cur) && (time() - (int) ($cur['at'] ?? 0)) < BACKUP_CATALOG_PERIOD) return;

        $domains = dirname(realpath(CTL_ROOT) ?: CTL_ROOT);
        $sizes = ctl_json_read('sizes.json', []);

        // 1) Germany host encrypted vault capsule (ops-host)
        $opsMan = @json_decode((string) @file_get_contents($domains . '/ops.hohedoc.ir/var/vault-capsule/manifest.json'), true);
        $germany = [
            'ready' => is_array($opsMan) && !empty($opsMan['ready']),
            'updated_utc' => $opsMan['updated_utc'] ?? null,
            'files_count' => (int) ($opsMan['files_count'] ?? 0),
            'artifacts' => is_array($opsMan) && !empty($opsMan['capsule_name']) ? [[
                'artifact' => (string) $opsMan['capsule_name'],
                'bytes' => (int) ($opsMan['bytes'] ?? 0),
                'sha256' => (string) ($opsMan['sha256'] ?? ''),
                'format' => 'HBV1',
            ]] : [],
        ];

        // 2) Iran host manifest & incremental delta (sites-host)
        $irMan = @json_decode((string) @file_get_contents($domains . '/sites.hohedoc.ir/var/iran-host-manifest.json'), true);
        $iran = [
            'ready' => is_array($irMan) && !empty($irMan['ok']),
            'updated_utc' => $irMan['updated_utc'] ?? null,
            'manifest_hash' => $irMan['manifest_hash'] ?? null,
            'items_count' => (int) ($irMan['workout_count'] ?? 0),
            'artifacts' => [],
        ];

        // 3) GitHub repos (from sizes.json)
        $ghRepos = [];
        foreach (($sizes['github']['repos'] ?? []) as $r) {
            if (!empty($r['name'])) {
                $ghRepos[] = [
                    'artifact' => preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $r['name']) . '.tar.gz',
                    'repo' => (string) $r['name'],
                    'bytes' => (int) ($r['bytes'] ?? 0),
                ];
            }
        }
        $github = ['ready' => count($ghRepos) > 0, 'repos_count' => count($ghRepos), 'artifacts' => $ghRepos];

        // 4) Google Drive BACKUP summary (from sizes.json)
        $driveAcc = ($sizes['drive'] ?? [])[0] ?? [];
        $drive = [
            'ready' => !empty($driveAcc['bytes']),
            'account' => (string) ($driveAcc['account'] ?? 'BACKUP'),
            'used_bytes' => (int) ($driveAcc['bytes'] ?? 0),
            'artifacts' => [],
        ];

        // Include any staged artifacts in var/backup-stage/<source>/
        $sources = ['github' => $github, 'iran' => $iran, 'germany' => $germany, 'drive' => $drive];
        foreach (array_keys(BACKUP_SOURCES) as $src) {
            $dir = backup_stage_dir($src);
            $seen = [];
            foreach ($sources[$src]['artifacts'] as $a) $seen[$a['artifact']] = true;
            foreach (glob($dir . '/*') ?: [] as $p) {
                if (!is_file($p)) continue;
                $bn = basename($p);
                if (!backup_artifact_ok($bn) || isset($seen[$bn])) continue;
                $sources[$src]['artifacts'][] = [
                    'artifact' => $bn,
                    'bytes' => (int) filesize($p),
                    'sha256' => (string) hash_file('sha256', $p),
                ];
                $sources[$src]['ready'] = true;
            }
        }

        ctl_json_write(BACKUP_CATALOG_FILE, [
            'at' => time(),
            'chunk_max_bytes' => BACKUP_MAX_CHUNK_BYTES,
            'sources' => $sources,
        ]);
    } finally {
        flock($lk, LOCK_UN);
    }
}

/** GET api.php?op=backup-manifest[&source=...] — primary hardware anchor only. */
function backup_manifest_for_device(string $callerDevice, ?string $sourceFilter = null, ?string $baseDirOverride = null): array {
    $deny = anchor_require_primary($callerDevice);
    if ($deny !== null) return [403, ['ok' => false, 'error' => $deny]];

    if ($sourceFilter !== null && $sourceFilter !== '' && !isset(BACKUP_SOURCES[$sourceFilter])) {
        return [400, ['ok' => false, 'error' => 'unknown source']];
    }

    $cat = ctl_json_read(BACKUP_CATALOG_FILE, null);
    $sources = is_array($cat['sources'] ?? null) ? $cat['sources'] : [
        'github' => ['ready' => false, 'artifacts' => []],
        'iran' => ['ready' => false, 'artifacts' => []],
        'germany' => ['ready' => false, 'artifacts' => []],
        'drive' => ['ready' => false, 'artifacts' => []],
    ];

    // If baseDirOverride is provided (e.g., selftest), reflect staged files directly
    if ($baseDirOverride !== null) {
        foreach (array_keys(BACKUP_SOURCES) as $src) {
            $dir = backup_stage_dir($src, $baseDirOverride);
            $list = [];
            foreach (glob($dir . '/*') ?: [] as $p) {
                if (!is_file($p)) continue;
                $bn = basename($p);
                if (!backup_artifact_ok($bn)) continue;
                $list[] = ['artifact' => $bn, 'bytes' => (int) filesize($p), 'sha256' => (string) hash_file('sha256', $p)];
            }
            $sources[$src] = ['ready' => count($list) > 0, 'artifacts' => $list];
        }
    }

    if ($sourceFilter !== null && $sourceFilter !== '') {
        $sources = [$sourceFilter => $sources[$sourceFilter]];
    }

    return [200, [
        'ok' => true,
        'at' => (int) ($cat['at'] ?? time()),
        'anchor_device' => $callerDevice,
        'chunk_max_bytes' => BACKUP_MAX_CHUNK_BYTES,
        'sources' => $sources,
    ]];
}

/** POST api.php?op=backup-chunk — resumable <= 8 MiB chunk download, primary hardware anchor only. */
function backup_chunk_for_device(string $callerDevice, array $in, ?string $baseDirOverride = null): array {
    $deny = anchor_require_primary($callerDevice);
    if ($deny !== null) return [403, ['ok' => false, 'error' => $deny]];

    $source = (string) ($in['source'] ?? '');
    $artifact = (string) ($in['artifact'] ?? '');
    $offset = isset($in['offset']) ? (int) $in['offset'] : 0;
    $length = isset($in['length']) ? (int) $in['length'] : BACKUP_MAX_CHUNK_BYTES;

    if (!isset(BACKUP_SOURCES[$source])) return [400, ['ok' => false, 'error' => 'unknown source']];
    if (!backup_artifact_ok($artifact)) return [400, ['ok' => false, 'error' => 'invalid artifact name']];
    if ($offset < 0) return [400, ['ok' => false, 'error' => 'negative offset']];
    if ($length <= 0 || $length > BACKUP_MAX_CHUNK_BYTES) {
        return [400, ['ok' => false, 'error' => 'length must be 1..' . BACKUP_MAX_CHUNK_BYTES . ' bytes']];
    }

    $path = backup_resolve_artifact($source, $artifact, $baseDirOverride);
    if ($path === null) return [404, ['ok' => false, 'error' => 'artifact not found']];

    $total = (int) filesize($path);
    if ($offset > $total || ($total > 0 && $offset === $total)) {
        return [416, ['ok' => false, 'error' => 'offset out of range', 'total_bytes' => $total]];
    }

    $fh = @fopen($path, 'rb');
    if (!$fh) return [500, ['ok' => false, 'error' => 'cannot open artifact']];
    try {
        if ($offset > 0 && fseek($fh, $offset) !== 0) {
            return [500, ['ok' => false, 'error' => 'seek failed']];
        }
        $readLen = min($length, $total - $offset);
        $data = $readLen > 0 ? (string) fread($fh, $readLen) : '';
    } finally {
        fclose($fh);
    }

    $actualLen = strlen($data);
    $nextOffset = $offset + $actualLen;
    return [200, [
        'ok' => true,
        'source' => $source,
        'artifact' => $artifact,
        'offset' => $offset,
        'length' => $actualLen,
        'next_offset' => $nextOffset,
        'total_bytes' => $total,
        'eof' => ($nextOffset >= $total),
        'chunk_sha256' => hash('sha256', $data),
        'file_sha256' => (string) hash_file('sha256', $path),
        'data_b64' => base64_encode($data),
    ]];
}
