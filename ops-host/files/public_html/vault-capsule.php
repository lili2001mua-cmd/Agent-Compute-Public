<?php
// Authenticated endpoint for Germany-host vault manifest & resumable Range download of latest.hbv1.
// Auth: header "X-Ops-Key: <OPS_ADMIN_KEY or VAULT_CAPSULE_KEY>" — unauthenticated requests receive 404.
// Never echoes any plaintext secret; only returns metadata manifest or the HBV1 master-passphrase encrypted blob.
require __DIR__ . '/../app/lib.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$given = trim((string) ($_SERVER['HTTP_X_OPS_KEY'] ?? ''));
$valid = array_filter([ops_secret('VAULT_CAPSULE_KEY'), ops_secret('OPS_ADMIN_KEY')], fn($k) => $k !== '');
$ok = false;
foreach ($valid as $k) {
    if ($given !== '' && hash_equals($k, $given)) { $ok = true; break; }
}
if (!$ok) { http_response_code(404); exit; }

$action = (string) ($_GET['action'] ?? 'manifest');
$dir = ops_var('vault-capsule');
$mfPath = $dir . '/manifest.json';
$capPath = $dir . '/latest.hbv1';

if ($action === 'manifest') {
    header('Content-Type: application/json; charset=utf-8');
    if (!is_file($mfPath)) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'manifest not built yet']);
        exit;
    }
    readfile($mfPath);
    exit;
}

if ($action === 'download') {
    if (!is_file($capPath)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'capsule not ready']);
        exit;
    }
    $size = filesize($capPath);
    $start = 0;
    $end = $size - 1;
    $status = 200;
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d+)-(\d*)$/', trim((string) $_SERVER['HTTP_RANGE']), $m)) {
        $start = (int) $m[1];
        if ($m[2] !== '') $end = min($size - 1, (int) $m[2]);
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        $status = 206;
    }
    $len = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: application/octet-stream');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $len);
    header('Content-Disposition: attachment; filename="germany-host-vault.hbv1"');
    if ($status === 206) header("Content-Range: bytes $start-$end/$size");
    $fh = fopen($capPath, 'rb');
    fseek($fh, $start);
    $left = $len;
    while ($left > 0 && !feof($fh)) {
        $chunk = fread($fh, min(65536, $left));
        if ($chunk === false) break;
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action (use manifest or download)']);
