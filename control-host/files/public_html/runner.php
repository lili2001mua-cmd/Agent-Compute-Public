<?php
// درِ رانرِ زنجیرهٔ دکمه (button-chain.yml ِ مخزنِ خصوصی) — فقط با امضای OIDC ِ خودِ گیت‌هاب، بی هیچ رازی.
//   POST runner.php?op=step3  {key}  → درخواستِ گامِ ۳ برای routine.hohedoc.ir (همان صفِ var/fire-req ِ op=step3-fire)
// ⛔ هیچ توکن یا رازی برگردانده نمی‌شود.
require __DIR__ . '/../app/lib.php';
require __DIR__ . '/../app/runner-auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function rout(int $code, array $x): void { http_response_code($code); echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rout(405, ['ok' => false, 'error' => 'POST only']);
$op = (string) ($_GET['op'] ?? '');
$claims = ctl_runner_claims((string) ($_SERVER['HTTP_X_RUNNER_OIDC'] ?? ''));
if ($claims === null) { ctl_log("runner auth fail op=$op"); rout(401, ['ok' => false, 'error' => 'oidc rejected']); }
$run = ($claims['workflow'] ?? '?') . '#' . preg_replace('/[^0-9]/', '', (string) ($claims['run_id'] ?? ''));

if ($op === 'step3') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $key = (string) ($in['key'] ?? '');
    if (!preg_match('/^b[0-9]{1,8}$/', $key)) rout(400, ['ok' => false, 'error' => 'bad key']);
    $fs = @json_decode((string) @file_get_contents(ctl_var('fire-state.json')), true) ?: [];
    if (($fs[$key]['state'] ?? '') === 'fired' && time() - (int) ($fs[$key]['at'] ?? 0) < 3600) rout(409, ['ok' => false, 'error' => 'step 3 for this bundle was started less than an hour ago']);
    $dir = ctl_var('fire-req');
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents($dir . '/' . time() . '-' . $key . '.json', json_encode(['key' => $key, 'at' => time(), 'device' => 'runner:' . $run]), LOCK_EX);
    ctl_log("runner step3 request $key <- $run");
    rout(200, ['ok' => true, 'key' => $key, 'note' => 'routine.hohedoc.ir fires it within 5 minutes']);
}
rout(404, ['ok' => false, 'error' => 'unknown op']);
