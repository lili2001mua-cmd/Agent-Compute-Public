<?php
// Heartbeat door: POST run=<run_id> phase=start|done — a hint only. The proof of done is the run file on the
// private repo's main, read by the host itself; a forged beat can change nothing. Nothing is echoed back.
require_once __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
$run = (string) ($_POST['run'] ?? '');
$phase = (string) ($_POST['phase'] ?? '');
if (!preg_match('/^[a-z0-9-]{3,80}$/', $run) || !in_array($phase, ['start', 'done'], true)) { http_response_code(400); echo '{"ok":false}'; exit; }
$lock = fopen(r_var('beats.lock'), 'c');
flock($lock, LOCK_EX);
$b = r_read_json('beats.json');
if (count($b) > 500) $b = array_slice($b, -400, null, true);
$b[$run][$phase] = time();
r_write_json('beats.json', $b);
flock($lock, LOCK_UN);
echo '{"ok":true}';
