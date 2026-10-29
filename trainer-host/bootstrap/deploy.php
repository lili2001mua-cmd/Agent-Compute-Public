<?php
// Pull-based deploy for trainer.hohedoc.ir — run by cron, never by a web request.
// Place at /home/<user>/domains/trainer.hohedoc.ir/deploy.php (next to secrets.php, outside public_html).
// Cron (every 5 min): /usr/local/bin/php /home/<user>/domains/trainer.hohedoc.ir/deploy.php
//
// It reads trainer-host/manifest.json from our own public repo, and for every listed file whose
// sha256 differs from the local copy it downloads the file, checks the sha256, and writes it.
// Only paths under app/ and public_html/ are accepted. Nothing is ever deleted except files the
// manifest marks as removed. No secret is read or sent.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const REPO = 'lili2001mua-cmd/Agent-Compute-Public';
const REF_FILE = __DIR__ . '/deploy-ref.txt';          // optional override of the branch
const DEFAULT_REF = 'claude/external-runner-server-review-aznpnn';
const BASE = __DIR__;
const LOG = __DIR__ . '/var/deploy.log';

$ref = is_file(REF_FILE) ? trim((string) file_get_contents(REF_FILE)) : DEFAULT_REF;
$raw = 'https://raw.githubusercontent.com/' . REPO . '/' . $ref . '/trainer-host/';

@mkdir(BASE . '/var', 0700, true);
$lock = fopen(BASE . '/var/deploy.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

function fetch(string $url): ?string {
    $ch = curl_init($url . '?t=' . time());
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'trainer-deploy']);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? $body : null;
}

function logline(string $s): void {
    file_put_contents(LOG, gmdate('c') . ' ' . $s . "\n", FILE_APPEND | LOCK_EX);
}

$m = fetch($raw . 'manifest.json');
$manifest = $m ? json_decode($m, true) : null;
if (!is_array($manifest) || !isset($manifest['files'])) { logline("manifest unreadable ref=$ref"); exit(1); }

$changed = 0;
foreach ($manifest['files'] as $path => $sha) {
    if (!preg_match('#^(app|public_html)/[A-Za-z0-9._/-]+$#', $path) || str_contains($path, '..')) {
        logline("skip bad path $path"); continue;
    }
    $dest = BASE . '/' . $path;
    if ($sha === null) { if (is_file($dest)) { unlink($dest); $changed++; } continue; }
    if (is_file($dest) && hash_file('sha256', $dest) === $sha) continue;
    $body = fetch($raw . 'files/' . $path);
    if ($body === null || hash('sha256', $body) !== $sha) { logline("sha mismatch $path"); continue; }
    @mkdir(dirname($dest), 0755, true);
    $tmp = $dest . '.tmp';
    file_put_contents($tmp, $body, LOCK_EX);
    rename($tmp, $dest);
    $changed++;
}
file_put_contents(BASE . '/var/deployed.json', json_encode([
    'version' => $manifest['version'] ?? '?', 'ref' => $ref, 'at' => gmdate('c'), 'changed' => $changed,
]), LOCK_EX);
if ($changed) logline("deployed version=" . ($manifest['version'] ?? '?') . " changed=$changed");
