<?php
// Claude door: authenticated dispatcher for a fixed set of safe, named jobs. No arbitrary code.
// Call: GET/POST https://trainer.hohedoc.ir/run.php?key=<ADMIN_KEY>&job=<name>[&arg=...]
// ADMIN_KEY lives in secrets.php (not in this repo). Output is JSON (no UI page, no layout).
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Key: the X-Trainer-Key header (preferred — a query string lands in access and proxy logs). ?key= still works for
// old callers and is logged as deprecated. No key ⇒ the repo-native pass (X-Trainer-Nonce + X-Trainer-Ref), which opens
// the READ-ONLY jobs only (1405-08-12 · orchestra github-native-doors, section C; trainer_repo_pass_ok in app/lib.php).
$want = trainer_secret('ADMIN_KEY');
$hdrKey = (string) ($_SERVER['HTTP_X_TRAINER_KEY'] ?? '');
$qKey = (string) ($_REQUEST['key'] ?? '');
$admin = $want !== '' && (hash_equals($want, $hdrKey) || hash_equals($want, $qKey));
if ($admin && $hdrKey === '') trainer_log('door', 'deprecated: key in query string');
$readOnly = ['getme', 'channels', 'ghcheck', 'runstatus', 'wfstate', 'armstatus'];
$pass = !$admin && trainer_repo_pass_ok((string) ($_SERVER['HTTP_X_TRAINER_NONCE'] ?? ''), (string) ($_SERVER['HTTP_X_TRAINER_REF'] ?? ''));
if (!$admin && !$pass) { http_response_code(404); exit; }

$job = preg_replace('/[^a-z_]/', '', (string) ($_REQUEST['job'] ?? ''));
$allowed = ['getme', 'channels', 'sendtest', 'ghcheck', 'dispatch', 'enable', 'runstatus', 'wfstate', 'nightly', 'post', 'edit', 'del', 'enableall', 'arm', 'disarm', 'armstatus'];
if (!in_array($job, $allowed, true)) { http_response_code(400); echo json_encode(['error' => 'unknown job']); exit; }
if (!$admin && !in_array($job, $readOnly, true)) { http_response_code(403); echo json_encode(['error' => 'admin key required']); exit; }

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
