<?php
// Public read door for the landing queue (ر۷): GET land.php?s=<session> → that session's latest answer (JSON);
// no s → summary of the last round. Readable with curl from a chat. No secret, no write.
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$all = @json_decode((string) @file_get_contents(ops_var('land-results.json')), true) ?: [];
$s = (string) ($_GET['s'] ?? '');
if ($s !== '') {
    if (!preg_match('#^[A-Za-z0-9._/-]{1,120}$#', $s)) { http_response_code(400); echo '{"error":"bad session"}'; exit; }
    if (!isset($all[$s])) { http_response_code(404); echo json_encode(['session' => $s, 'state' => 'unknown']); exit; }
    echo json_encode(['session' => $s] + $all[$s], JSON_UNESCAPED_SLASHES);
    exit;
}
echo json_encode(['lander' => @json_decode((string) @file_get_contents(ops_var('lander.json')), true), 'sessions' => count($all)], JSON_UNESCAPED_SLASHES);
