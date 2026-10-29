<?php
// Heartbeat door: POST id=<routine> [sha=<commit>] — "this routine's ledger just reached main".
// No key: a forged beat can only delay one alert, never read or change anything. Only ids in OPS_WATCH are
// accepted, one beat per id per 10 minutes, and nothing from the request is ever echoed back.
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
$id = (string) ($_POST['id'] ?? '');
if (!array_key_exists($id, OPS_WATCH)) { http_response_code(404); echo '{"ok":false}'; exit; }
$sha = preg_match('/^[a-f0-9]{7,40}$/', (string) ($_POST['sha'] ?? '')) ? (string) $_POST['sha'] : null;
$lock = fopen(ops_var('beats.lock'), 'c');
flock($lock, LOCK_EX);
$s = ops_state();
if (time() - (int) ($s[$id]['at'] ?? 0) >= 600) {
    $s[$id] = array_merge($s[$id] ?? [], ['at' => time(), 'sha' => $sha]);
    ops_save_state($s);
}
flock($lock, LOCK_UN);
echo '{"ok":true}';
