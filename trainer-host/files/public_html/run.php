<?php
// Claude door: authenticated dispatcher for a fixed set of safe, named jobs. No arbitrary code.
// Call: GET/POST https://trainer.hohedoc.ir/run.php?key=<ADMIN_KEY>&job=<name>[&arg=...]
// ADMIN_KEY lives in secrets.php (not in this repo). Output is JSON (no UI page, no layout).
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$key = (string) ($_REQUEST['key'] ?? '');
$want = trainer_secret('ADMIN_KEY');
if ($want === '' || !hash_equals($want, $key)) { http_response_code(404); exit; }

$job = preg_replace('/[^a-z_]/', '', (string) ($_REQUEST['job'] ?? ''));
$allowed = ['getme', 'channels', 'sendtest'];
if (!in_array($job, $allowed, true)) { http_response_code(400); echo json_encode(['error' => 'unknown job']); exit; }

require __DIR__ . '/../app/jobs/channels.php';
$fn = 'job_' . $job;
$args = $_REQUEST;
unset($args['key'], $args['job']);
try {
    echo json_encode(['ok' => true, 'job' => $job, 'result' => $fn($args)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'job' => $job, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
