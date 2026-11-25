<?php
// Authenticated endpoint for Iran-host incremental backup manifest & delta (Holding-Backup-Vault/v1/03-iran-host/).
// Auth: header "X-Sites-Key: <DH_ADMIN_KEY or SITES_BACKUP_KEY>" — unauthenticated requests receive 404.
// Never echoes any plaintext secret; serves only metadata manifest & incremental delta list.
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$given = trim((string) ($_SERVER['HTTP_X_SITES_KEY'] ?? ''));
$valid = array_filter([dh_secret('SITES_BACKUP_KEY'), dh_secret('DH_ADMIN_KEY')], fn($k) => $k !== '');
$ok = false;
foreach ($valid as $k) {
    if ($given !== '' && hash_equals($k, $given)) { $ok = true; break; }
}
if (!$ok) { http_response_code(404); exit; }

$action = (string) ($_GET['action'] ?? 'manifest');
$mfPath = dh_var('iran-host-backup') . '/manifest.json';
if (!is_file($mfPath)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'iran-host manifest not built yet']);
    exit;
}

$m = json_decode((string) file_get_contents($mfPath), true);
if (!is_array($m)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'corrupt manifest']);
    exit;
}

if ($action === 'manifest') {
    echo json_encode(['ok' => true, 'manifest' => $m], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'delta') {
    $since = trim((string) ($_GET['since'] ?? ''));
    $upToDate = ($since !== '' && hash_equals((string) ($m['snapshot_hash'] ?? ''), $since));
    echo json_encode([
        'ok' => true,
        'up_to_date' => $upToDate,
        'snapshot_hash' => $m['snapshot_hash'] ?? null,
        'delta_summary' => $upToDate ? ['added' => 0, 'changed' => 0, 'removed' => 0, 'unchanged' => $m['items_count'] ?? 0] : ($m['delta_summary'] ?? null),
        'delta' => $upToDate ? ['added' => [], 'changed' => [], 'removed' => [], 'unchanged_count' => $m['items_count'] ?? 0] : ($m['delta'] ?? null),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action (use manifest or delta)']);
