<?php
// Shared helpers for control.hohedoc.ir — the control server of the My-Control-Project app.
// Design: Agent-Project-Backup/Memory-Workflow/idea-orchestrations/holding-control-connection/HANDBOOK.md (v4).
// Secrets live in ../secrets.php (next to deploy.php, outside public_html, never in git):
//   const GH_TOKEN    = '<fine-grained token: only Agent-Project-Backup · Actions RW · Contents RW · Metadata R>';
//   const DEVICE_KEYS = ['phone1' => '<base64 32-byte key shown once by the app>'];
// No Claude, Google or Telegram credential is used here. Nothing is ever sent to Claude.
const CTL_ROOT = __DIR__ . '/..';
const CTL_REPO = 'lili2001mua-cmd/Agent-Project-Backup';   // the holding's private repo (same as ops-host)
const CTL_BRANCH = 'main';
const CTL_SKEW = 300;          // seconds a signed request may be old or early
const CTL_CHALLENGE_TTL = 600; // second confirmation must come within 10 minutes (HANDBOOK: command life 10 min)

function ctl_secrets_loaded(): bool {
    $f = CTL_ROOT . '/secrets.php';
    if (is_file($f)) require_once $f;
    return defined('GH_TOKEN') && defined('DEVICE_KEYS');
}

function ctl_var(string $name): string {
    $dir = CTL_ROOT . '/var';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir . '/' . $name;
}

function ctl_json_read(string $name, $default) {
    $f = ctl_var($name);
    $x = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($x) ? $x : $default;
}

function ctl_json_write(string $name, $data): void {
    $f = ctl_var($name);
    file_put_contents($f . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($f . '.tmp', $f);
}

function ctl_log(string $s): void {
    file_put_contents(ctl_var('control.log'), gmdate('c') . ' ' . $s . "\n", FILE_APPEND | LOCK_EX);
}

/** GitHub REST call. Returns [code, decoded-json-or-null, raw]. */
function gh(string $method, string $path, $body = null): array {
    $ch = curl_init('https://api.github.com' . $path);
    $h = ['Authorization: Bearer ' . GH_TOKEN, 'Accept: application/vnd.github+json',
          'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: control-hohedoc'];
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($raw) ? json_decode($raw, true) : null, is_string($raw) ? $raw : ''];
}

/** Text of a file on main, or null. */
function gh_file(string $path): ?string {
    [$c, $j] = gh('GET', '/repos/' . CTL_REPO . '/contents/' . rawurlencode_path($path) . '?ref=' . CTL_BRANCH);
    return ($c === 200 && isset($j['content'])) ? base64_decode($j['content']) : null;
}

function rawurlencode_path(string $p): string {
    return implode('/', array_map('rawurlencode', explode('/', $p)));
}

/** Verify a signed request. Signature = hex HMAC-SHA256(key, "op\nts\nnonce\nsha256(body)"). */
function ctl_auth(string $op, string $body): ?string {
    $dev = (string) ($_SERVER['HTTP_X_HC_DEVICE'] ?? '');
    $ts = (int) ($_SERVER['HTTP_X_HC_TS'] ?? 0);
    $nonce = (string) ($_SERVER['HTTP_X_HC_NONCE'] ?? '');
    $sig = (string) ($_SERVER['HTTP_X_HC_SIG'] ?? '');
    $keys = DEVICE_KEYS;
    if (!isset($keys[$dev]) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)) return 'bad device or nonce';
    if (abs(time() - $ts) > CTL_SKEW) return 'clock skew';
    $key = base64_decode($keys[$dev], true);
    if ($key === false || strlen($key) < 32) return 'bad key on host';
    $want = hash_hmac('sha256', $op . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body), $key);
    if (!hash_equals($want, strtolower($sig))) return 'bad signature';
    // replay guard: each nonce once within the skew window
    $lock = fopen(ctl_var('nonces.lock'), 'c'); flock($lock, LOCK_EX);
    $seen = ctl_json_read('nonces.json', []);
    foreach ($seen as $n => $t) if ($t < time() - 2 * CTL_SKEW) unset($seen[$n]);
    if (isset($seen[$dev . ':' . $nonce])) { flock($lock, LOCK_UN); return 'replay'; }
    $seen[$dev . ':' . $nonce] = time();
    ctl_json_write('nonces.json', $seen);
    flock($lock, LOCK_UN);
    return null;
}
