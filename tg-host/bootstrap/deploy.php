<?php
// Pull-based deploy for tg.hohedoc.ir — run by cron only, never by a web request.
// Place at /home/<user>/domains/tg.hohedoc.ir/deploy.php (next to secrets.php and deploy-ref.txt,
// outside public_html). Cron (every 5 min): /usr/local/bin/php /home/<user>/domains/tg.hohedoc.ir/deploy.php
//
// Security model (five-expert panel, 1405-08-06, user decision 2 = manual commit pin, no signing key):
//  - The ref comes ONLY from deploy-ref.txt, which the user writes in the panel's file manager.
//  - A full 40-hex commit SHA is always accepted (content-addressed: a later push changes nothing).
//  - The phase-1 ref "tg-phase1" (a branch, fast-forward only) is accepted ONLY while no Telegram session exists on this host
//    (var/session/ absent). The moment a session exists, only a pinned SHA deploys — enforced here.
//  - The manifest may only write under app/ and public_html/, never into var/, vendor-*, secrets*,
//    deploy*, or dot-files. var/ lives outside both, so a manifest can never touch the session.
// No secret is read or sent.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const REPO = 'lili2001mua-cmd/Agent-Compute-Public';
const BASE = __DIR__;
const REF_FILE = BASE . '/deploy-ref.txt';
const PHASE1_TAG = 'tg-phase1';
const LOG = BASE . '/var/deploy.log';

@mkdir(BASE . '/var', 0700, true);
function logline(string $s): void { file_put_contents(LOG, gmdate('c') . ' ' . $s . "\n", FILE_APPEND | LOCK_EX); }
function state(array $s): void { file_put_contents(BASE . '/var/deployed.json', json_encode($s + ['at' => gmdate('c')]), LOCK_EX); }

$lock = fopen(BASE . '/var/deploy.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

$ref = is_file(REF_FILE) ? trim((string) file_get_contents(REF_FILE)) : '';
$sessionExists = is_dir(BASE . '/var/session');
$isSha = (bool) preg_match('/^[0-9a-f]{40}$/', $ref);
$isPhase1 = $ref === PHASE1_TAG && !$sessionExists;
if (!$isSha && !$isPhase1) {
    state(['ok' => false, 'refused' => $ref === '' ? 'no deploy-ref' : ($ref === PHASE1_TAG ? 'phase-1 ref refused: session exists, pin a SHA' : 'ref is not a 40-hex SHA')]);
    logline('refused ref');
    exit(1);
}
$raw = 'https://raw.githubusercontent.com/' . REPO . '/' . $ref . '/tg-host/';

function fetch(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'tg-deploy']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? $body : null;
}

function allowed_path(string $p): bool {
    if (!preg_match('#^(app|public_html)/[A-Za-z0-9._/-]+$#', $p) || str_contains($p, '..')) return false;
    foreach (explode('/', $p) as $seg) {
        if ($seg === 'var' || str_starts_with($seg, 'vendor-') || str_starts_with($seg, 'secrets')
            || str_starts_with($seg, 'deploy') || str_starts_with($seg, '.')) return false;
    }
    return true;
}

$m = fetch($raw . 'manifest.json' . ($isSha ? '' : '?t=' . time()));
$manifest = $m ? json_decode($m, true) : null;
if (!is_array($manifest) || !isset($manifest['files'])) { state(['ok' => false, 'refused' => 'manifest unreadable']); logline('manifest unreadable'); exit(1); }

$changed = 0; $skipped = 0;
foreach ($manifest['files'] as $path => $sha) {
    if (!allowed_path($path)) { $skipped++; logline("skip path $path"); continue; }
    $dest = BASE . '/' . $path;
    if ($sha === null) { if (is_file($dest)) { unlink($dest); $changed++; } continue; }
    if (!preg_match('/^[0-9a-f]{64}$/', (string) $sha)) { $skipped++; continue; }
    if (is_file($dest) && hash_file('sha256', $dest) === $sha) continue;
    $body = fetch($raw . 'files/' . $path);
    if ($body === null || hash('sha256', $body) !== $sha) { logline("sha mismatch $path"); $skipped++; continue; }
    @mkdir(dirname($dest), 0755, true);
    $tmp = $dest . '.tmp';
    file_put_contents($tmp, $body, LOCK_EX);
    rename($tmp, $dest);
    $changed++;
}
state(['ok' => true, 'version' => $manifest['version'] ?? '?', 'ref_kind' => $isSha ? 'sha' : 'phase1-tag', 'changed' => $changed, 'skipped' => $skipped]);
if ($changed) logline('deployed version=' . ($manifest['version'] ?? '?') . " changed=$changed");
