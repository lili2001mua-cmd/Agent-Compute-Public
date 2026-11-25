<?php
// iran_host_manifest.php — Incremental manifest & delta builder for the Iran host (Holding-Backup-Vault/v1/03-iran-host/).
// Runs alongside the dual-DB architecture (STY-02/03) and weekly Telegram DB backup from sites-host/files/app/tick.php.
// Tracks:
//   1) Workout media/PDF index on https://relay.hohe1.ir/workout/index.json
//   2) Dual-DB latest backup state (from My-Sites / phone-backup metadata, never plaintext secrets)
//   3) Site uploads & local config inventory metadata (delta-friendly: added / changed / removed)
require_once __DIR__ . '/lib.php';

const IRAN_WORKOUT_INDEX_URL = 'https://relay.hohe1.ir/workout/index.json';
const IRAN_MANIFEST_INTERVAL_S = 21600; // every 6 hours

/** Pure delta calculator: returns added, changed, removed, unchanged counts & paths for incremental sync. */
function iran_backup_diff(array $prevMap, array $curMap): array {
    $added = [];
    $changed = [];
    $removed = [];
    $unchanged = 0;
    foreach ($curMap as $path => $meta) {
        $sig = (string) ($meta['sha256'] ?? (($meta['bytes'] ?? 0) . ':' . ($meta['mtime'] ?? 0)));
        if (!isset($prevMap[$path])) {
            $added[] = ['path' => $path] + $meta;
        } else {
            $psig = (string) ($prevMap[$path]['sha256'] ?? (($prevMap[$path]['bytes'] ?? 0) . ':' . ($prevMap[$path]['mtime'] ?? 0)));
            if ($psig !== $sig) $changed[] = ['path' => $path] + $meta;
            else $unchanged++;
        }
    }
    foreach ($prevMap as $path => $_) {
        if (!isset($curMap[$path])) $removed[] = $path;
    }
    return [
        'added' => $added,
        'changed' => $changed,
        'removed' => $removed,
        'unchanged_count' => $unchanged,
    ];
}

function iran_fetch_workout_index(?string $url = null): array {
    $url = $url ?: IRAN_WORKOUT_INDEX_URL;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['User-Agent: sites-host-iran-manifest'],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($raw)) return ['ok' => false, 'http' => $code, 'items' => []];
    $j = json_decode($raw, true);
    if (!is_array($j)) return ['ok' => false, 'http' => $code, 'items' => []];
    $items = [];
    foreach ($j as $row) {
        if (!is_array($row) || empty($row['href'])) continue;
        $href = ltrim((string) $row['href'], '/');
        $items['workout/' . $href] = [
            'section' => 'workout',
            'name' => (string) ($row['name'] ?? basename($href)),
            'url' => 'https://relay.hohe1.ir/workout/' . $href,
            'bytes' => (int) ($row['bytes'] ?? 0),
            'sha256' => (string) ($row['sha256'] ?? ''),
            'folder' => (string) ($row['folder'] ?? ''),
        ];
    }
    ksort($items);
    return ['ok' => true, 'http' => 200, 'items' => $items];
}

function iran_backup_build_manifest(array $items, array $prevManifest, array $dualDbMeta): array {
    $prevItems = is_array($prevManifest['items'] ?? null) ? $prevManifest['items'] : [];
    $delta = iran_backup_diff($prevItems, $items);
    $totalBytes = 0;
    foreach ($items as $m) $totalBytes += (int) ($m['bytes'] ?? 0);
    $snapshotHash = hash('sha256', json_encode(array_map(
        fn($m) => [$m['bytes'] ?? 0, $m['sha256'] ?? ''],
        $items
    )));
    return [
        'schema' => 'holding-backup-vault/v1/iran-host',
        'checked_at' => time(),
        'updated_utc' => gmdate('c'),
        'snapshot_hash' => $snapshotHash,
        'prev_snapshot_hash' => $prevManifest['snapshot_hash'] ?? null,
        'items_count' => count($items),
        'total_bytes' => $totalBytes,
        'dual_db' => $dualDbMeta,
        'delta_summary' => [
            'added' => count($delta['added']),
            'changed' => count($delta['changed']),
            'removed' => count($delta['removed']),
            'unchanged' => $delta['unchanged_count'],
        ],
        'delta' => $delta,
        'items' => $items,
    ];
}

function dh_iran_host_manifest_tick(): void {
    if (PHP_SAPI !== 'cli') return;
    $dir = dh_var('iran-host-backup');
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $mfPath = $dir . '/manifest.json';
    $prev = @json_decode((string) @file_get_contents($mfPath), true) ?: [];
    if (!empty($prev['checked_at']) && (time() - (int) $prev['checked_at']) < IRAN_MANIFEST_INTERVAL_S && is_file($mfPath)) {
        return;
    }

    $w = iran_fetch_workout_index();
    $items = $w['ok'] ? $w['items'] : (is_array($prev['items'] ?? null) ? $prev['items'] : []);
    $weeklyState = @json_decode((string) @file_get_contents(dh_var('weekly-tg-backup.json')), true) ?: null;
    $dualDbMeta = [
        'architecture' => 'dual-db (STY-02/03) + weekly zero-AI Telegram backup',
        'workout_index_ok' => $w['ok'],
        'weekly_tg_backup' => $weeklyState,
    ];

    $manifest = iran_backup_build_manifest($items, $prev, $dualDbMeta);
    file_put_contents($mfPath . '.tmp', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($mfPath . '.tmp', 0600);
    rename($mfPath . '.tmp', $mfPath);
}
