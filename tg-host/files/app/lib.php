<?php
// Shared helpers for tg.hohedoc.ir. Layout (panel 1405-08-06):
//   <base>/secrets.php, deploy.php, deploy-ref.txt   — placed by the user, never by the manifest
//   <base>/var/                                       — queue, jobs, session, logs (0700; manifest can never write here)
//   <base>/app/                                       — code (deployed)
//   <base>/public_html/                               — door + status (deployed); public_html/m/<128-bit>/ = expiring media
define('TG_BASE', (string) realpath(__DIR__ . '/..'));
const TG_DISK_CAP_BYTES = 1536 * 1024 * 1024;   // ~1.5 GB for var/ + public_html/m (panel item, own tracked usage)
const TG_QUEUE_CAP = 50;
const TG_TICK_BUDGET_S = 240;                     // drain budget per tick (calibration job may exceed it on purpose)
const TG_MEDIA_TTL_S = 72 * 3600;

function tg_dir(string $p): string { if (!is_dir($p)) { @mkdir($p, 0700, true); } return $p; }
function tg_var(string $rel = ''): string { $d = tg_dir(TG_BASE . '/var'); return $rel === '' ? $d : $d . '/' . $rel; }
function tg_secrets(): array {
    static $s = null;
    if ($s === null) { $s = []; $f = TG_BASE . '/secrets.php'; if (is_file($f)) { $a = include $f; if (is_array($a)) $s = $a; } }
    return $s;
}
function tg_write_json(string $path, array $data): void {
    tg_dir(dirname($path));
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $path);
}
function tg_read_json(string $path): ?array { $s = @file_get_contents($path); $d = $s === false ? null : json_decode($s, true); return is_array($d) ? $d : null; }
function tg_id(): string { return bin2hex(random_bytes(16)); }
function tg_log(string $stream, string $line): void {
    // ⛔ never pass a secret, code, phone or message text here — counts and states only.
    file_put_contents(tg_var('log-' . $stream . '.log'), gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}
/** Role of a presented key: 'admin' | 'read' | null. Only sha256 hashes live in the (public) code. */
function tg_key_role(string $key): ?string {
    if (strlen($key) < 32) return null;
    $hashes = include __DIR__ . '/keys.php';
    $h = hash('sha256', $key);
    foreach ($hashes as $role => $hash) { if (is_string($hash) && hash_equals($hash, $h)) return $role; }
    return null;
}
// Repo-native door (1405-08-11 · orchestra github-native-doors): no stored key. The caller picks a random nonce, pushes
// tg-auth/<sha256(nonce)>.json ({"exp": unix-time, ≤26 h ahead}) to a BRANCH of the public compute repo, then sends
// X-Tg-Nonce + X-Tg-Ref. ⛔ Only a real branch proves push access: raw.githubusercontent.com also serves refs/pull/N/head
// and fork commits through this repo's URL (flaw repo-auth-accepts-pull-request-refs), so the GitHub API must confirm
// the branch. This path grants the READ role only; admin jobs still need the admin key. 20 failures / 10 min close it.
const TG_AUTH_REPO = 'lili2001mua-cmd/Agent-Compute-Public';
function tg_https_get(string $url, int $timeout = 20): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'], CURLOPT_USERAGENT => 'tg-host']);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = $raw === false ? null : json_decode((string) $raw, true);
    return [$code, is_array($j) ? $j : null];
}
function tg_ref_is_branch(string $ref): bool {
    if (!preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $ref) || strlen($ref) > 120 || str_contains($ref, '..')) return false;
    if (preg_match('#^(refs|pull)(/|$)#i', $ref) || preg_match('/^[0-9a-f]{7,40}$/i', $ref)) return false;
    $cache = tg_dir(tg_var('auth')) . '/branch-' . hash('sha256', $ref);
    $t = (int) @file_get_contents($cache);
    if ($t > time() - 3600) return true;
    [$code, $r] = tg_https_get('https://api.github.com/repos/' . TG_AUTH_REPO . '/branches/' . str_replace('%2F', '/', rawurlencode($ref)));
    if ($code !== 200 || !is_array($r) || ($r['name'] ?? '') !== $ref) return false;
    file_put_contents($cache, (string) time(), LOCK_EX);
    return true;
}
function tg_repo_role(string $nonce, string $ref): ?string {
    if ($nonce === '' && $ref === '') return null;
    $f = tg_var('door-fail.json');
    $st = tg_read_json($f) ?? ['n' => 0, 't' => 0];
    if (time() - (int) $st['t'] > 600) $st = ['n' => 0, 't' => time()];
    if ((int) $st['n'] >= 20) return null;
    $ok = false;
    if (preg_match('/^[a-f0-9]{64}$/', $nonce) && tg_ref_is_branch($ref)) {
        $h = hash('sha256', $nonce);
        $cache = tg_dir(tg_var('auth')) . '/' . $h;
        $exp = (int) @file_get_contents($cache);
        if ($exp > time()) $ok = true;
        else {
            [$code, $r] = tg_https_get('https://raw.githubusercontent.com/' . TG_AUTH_REPO . '/' . $ref . '/tg-auth/' . $h . '.json');
            $exp = (int) (($r ?? [])['exp'] ?? 0);
            if ($code === 200 && $exp > time() && $exp <= time() + 26 * 3600) { file_put_contents($cache, (string) $exp, LOCK_EX); $ok = true; }
        }
    }
    if ($ok) return 'read';
    $st['n'] = (int) $st['n'] + 1;
    tg_write_json($f, $st);
    return null;
}
function tg_dir_bytes(string $dir): int {
    if (!is_dir($dir)) return 0;
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) { $n += $f->getSize(); }
    return $n;
}
function tg_usage_bytes(): int { return tg_dir_bytes(TG_BASE . '/var') + tg_dir_bytes(TG_BASE . '/public_html/m'); }
function tg_rm_r(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    if (!is_dir($p)) return;
    foreach (scandir($p) ?: [] as $f) { if ($f !== '.' && $f !== '..') tg_rm_r($p . '/' . $f); }
    @rmdir($p);
}
function tg_job_dir(string $id): string { return tg_var('jobs/' . $id); }
function tg_status(string $id, array $patch): void {
    $p = tg_job_dir($id) . '/status.json';
    $cur = tg_read_json($p) ?? [];
    tg_write_json($p, array_merge($cur, $patch, ['updated_at' => time()]));
}
