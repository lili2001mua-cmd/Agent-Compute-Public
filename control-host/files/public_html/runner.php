<?php
// درِ رانرِ زنجیرهٔ دکمه (button-chain.yml ِ مخزنِ خصوصی) — فقط با امضای OIDC ِ خودِ گیت‌هاب، بی هیچ رازی.
//   POST runner.php?op=step3  {key}  → درخواستِ گامِ ۳ برای routine.hohedoc.ir (همان صفِ var/fire-req ِ op=step3-fire)
//   POST runner.php?op=enrich {key, media_url, source, whisper_model, media_urls} → دیسپچِ enrich.yml ِ مخزنِ عمومی (گامِ ۲)
//   POST runner.php?op=artifact {artifact_id} → {url}: نشانیِ امضاشدهٔ کوتاه‌عمرِ زیپِ یک آرتیفکتِ مخزنِ عمومی (فرودِ گامِ ۲)
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
if ($op === 'enrich') {
    // گامِ ۲: فقط همین یک ورک‌فلوی عمومی، با ورودی‌های سنجیده؛ لینک‌ها فقط لینکِ دانلودِ مستقیمِ درایو.
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $drive = '~^https://drive\.google\.com/uc\?export=download&id=[A-Za-z0-9_-]{10,120}$~';
    $key = (string) ($in['key'] ?? ''); $url = (string) ($in['media_url'] ?? '');
    $src = (string) ($in['source'] ?? 'telegram'); $model = (string) ($in['whisper_model'] ?? 'medium');
    $urls = (string) ($in['media_urls'] ?? '');
    if (!preg_match('/^b[0-9]{1,8}$/', $key) || !preg_match($drive, $url)) rout(400, ['ok' => false, 'error' => 'bad key or media_url']);
    if (!in_array($src, ['telegram', 'drive'], true) || !in_array($model, ['small', 'medium'], true)) rout(400, ['ok' => false, 'error' => 'bad source or model']);
    if (strlen($urls) > 20000) rout(400, ['ok' => false, 'error' => 'media_urls too long']);
    foreach (array_filter(explode("\n", $urls), 'strlen') as $line) {
        if (!preg_match('~^\S{1,160} (\S+)$~', $line, $m) || !preg_match($drive, $m[1])) rout(400, ['ok' => false, 'error' => 'bad media_urls line']);
    }
    $inputs = ['key' => $key, 'media_url' => $url, 'source' => $src, 'whisper_model' => $model];
    if ($urls !== '') $inputs['media_urls'] = $urls;
    [$code, , $raw] = gh('POST', '/repos/lili2001mua-cmd/Agent-Compute-Public/actions/workflows/enrich.yml/dispatches', ['ref' => 'main', 'inputs' => $inputs]);
    ctl_log("runner enrich $key -> http=$code <- $run");
    if ($code !== 204) rout(502, ['ok' => false, 'error' => 'dispatch failed: HTTP ' . $code . ' ' . mb_substr($raw, 0, 200)]);
    rout(200, ['ok' => true, 'key' => $key]);
}
if ($op === 'artifact') {
    // فقط آرتیفکتِ مخزنِ عمومی و فقط نامِ enriched-<key> ؛ پاسخ نشانیِ ریدایرکتِ یک‌دقیقه‌ایِ گیت‌هاب است، نه توکن.
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $id = (int) ($in['artifact_id'] ?? 0);
    if ($id <= 0) rout(400, ['ok' => false, 'error' => 'bad artifact_id']);
    [$c0, $meta] = gh('GET', '/repos/lili2001mua-cmd/Agent-Compute-Public/actions/artifacts/' . $id);
    if ($c0 !== 200 || !preg_match('/^enriched-b[0-9]{1,8}$/', (string) ($meta['name'] ?? ''))) rout(404, ['ok' => false, 'error' => 'artifact not allowed']);
    $ch = curl_init('https://api.github.com/repos/lili2001mua-cmd/Agent-Compute-Public/actions/artifacts/' . $id . '/zip');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . ctl_gh_token(), 'Accept: application/vnd.github+json', 'User-Agent: control-hohedoc']]);
    $raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 302 || !preg_match('/^location:\s*(https:\/\/\S+)/mi', $raw, $m)) rout(502, ['ok' => false, 'error' => 'no redirect: HTTP ' . $code]);
    ctl_log("runner artifact $id ({$meta['name']}) <- $run");
    rout(200, ['ok' => true, 'url' => trim($m[1])]);
}
rout(404, ['ok' => false, 'error' => 'unknown op']);
