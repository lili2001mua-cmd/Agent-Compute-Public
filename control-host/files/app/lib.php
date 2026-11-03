<?php
// Shared helpers for control.hohedoc.ir — the control server of the My-Control-Project app.
// Design: Agent-Project-Backup/Memory-Workflow/idea-orchestrations/holding-control-connection/HANDBOOK.md (v4).
// Secrets (never in git):
//   GitHub token  = W-HOST, read from the holding vault /home/<user>/holding-vault/gh-write-host.php
//                   (outside every domain; handbook github-four-tokens). The web side cannot open that
//                   folder (open_basedir), so the cron copies it into var/gh-token.php (chmod 600).
//   ../secrets.php = const DEVICE_KEYS = ['phone1' => '<base64 32-byte key shown by the app>'];
//                   (an old const GH_TOKEN there still works, but the vault wins when both exist)
// No Claude, Google or Telegram credential is used here. Nothing is ever sent to Claude.
const CTL_ROOT = __DIR__ . '/..';
const CTL_REPO = 'lili2001mua-cmd/Agent-Project-Backup';   // the holding's private repo (same as ops-host)
const CTL_BRANCH = 'main';
const CTL_SKEW = 300;          // seconds a signed request may be old or early
const CTL_CHALLENGE_TTL = 600; // second confirmation must come within 10 minutes (HANDBOOK: command life 10 min)

/** Path of the holding vault file: two levels above the domain folder (/home/<user>/holding-vault/). */
function ctl_vault_file(): string {
    $root = realpath(CTL_ROOT) ?: CTL_ROOT;
    // home/holding-vault first; domains/holding-vault also accepted (still outside every domain, never served)
    foreach ([dirname($root, 2), dirname($root)] as $base) {
        $f = $base . '/holding-vault/gh-write-host.php';
        if (@is_file($f)) return $f;
    }
    return dirname($root, 2) . '/holding-vault/gh-write-host.php';
}

/** Cron only: copy W-HOST from the vault into var/gh-token.php when it changed. Returns a short state. */
function ctl_vault_sync(): string {
    if (PHP_SAPI !== 'cli') return 'web';
    $f = ctl_vault_file();
    if (!is_readable($f)) return 'vault missing';
    $t = include $f;
    if (!is_string($t) || !preg_match('/^(github_pat_|ghp_)[A-Za-z0-9_]{20,}$/', trim($t))) return 'vault unreadable';
    $t = trim($t);
    $dst = ctl_var('gh-token.php');
    $body = "<?php return '" . $t . "';\n";
    if (!is_file($dst) || file_get_contents($dst) !== $body) {
        file_put_contents($dst . '.tmp', $body, LOCK_EX);
        chmod($dst . '.tmp', 0600);
        rename($dst . '.tmp', $dst);
        ctl_log('gh token refreshed from vault');
    }
    return 'ok';
}

/** The GitHub token: the vault copy first, then an old const GH_TOKEN in secrets.php. */
function ctl_gh_token(): ?string {
    static $t = null;
    if ($t !== null) return $t ?: null;
    $f = ctl_var('gh-token.php');
    $v = is_file($f) ? include $f : null;
    $t = is_string($v) && $v !== '' ? $v : (defined('GH_TOKEN') ? (string) GH_TOKEN : '');
    return $t ?: null;
}

function ctl_secrets_loaded(): bool {
    $f = CTL_ROOT . '/secrets.php';
    if (is_file($f)) require_once $f;
    return defined('DEVICE_KEYS') && ctl_gh_token() !== null;
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
    $h = ['Authorization: Bearer ' . ctl_gh_token(), 'Accept: application/vnd.github+json',
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
