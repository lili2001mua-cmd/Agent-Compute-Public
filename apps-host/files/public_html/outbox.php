<?php
// Public outbox of apps.hohedoc.ir for the Iran relay (1405-08-11). Lists workout files waiting to be pulled and serves
// one by its sha256. Nothing here is secret: the same files become public on https://relay.hohe1.ir/workout/ .
require __DIR__ . '/../app/jobs.php';
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
$get = (string) ($_GET['get'] ?? '');
if ($get === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'files' => outbox_list()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!preg_match('/^[a-f0-9]{64}$/', $get) || !in_array($get, array_column(outbox_list(), 'sha256'), true)) { http_response_code(404); exit; }
$p = outbox_dir() . "/$get";
if (!is_file($p)) { http_response_code(404); exit; }
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($p));
readfile($p);
