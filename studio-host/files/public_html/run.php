<?php
// Claude door of studio.hohedoc.ir: a fixed allow-list of named jobs, no arbitrary code. JSON only, no page.
// Call: POST https://studio.hohedoc.ir/run.php  header "X-Studio-Key: <ADMIN_KEY>"  fields job=<name> & args.
// ⛔ The key is accepted ONLY as a header — a query string ends up in access logs and proxy logs.
require __DIR__ . '/../app/jobs.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (!studio_key_ok()) { http_response_code(404); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$job = preg_replace('/[^a-z_]/', '', (string) ($_POST['job'] ?? ''));
$inline = ['hf_check', 'hf_submit', 'hf_status', 'hf_login_link', 'hf_login_status', 'hf_register', 'hf_login_start', 'hf_finish', 'hf_device_start', 'hf_device_poll', 'hf_tools', 'hf_call', 'asset_upload', 'result'];
$args = $_POST;
unset($args['job']);
try {
    if (in_array($job, QUEUED, true)) $res = job_enqueue($job, $args);
    elseif (in_array($job, $inline, true)) $res = ('job_' . $job)($args);
    else { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'unknown job']); exit; }
    echo json_encode(['ok' => true, 'job' => $job, 'result' => $res], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    studio_log('door', "$job failed: " . $e->getMessage());
    echo json_encode(['ok' => false, 'job' => $job, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
