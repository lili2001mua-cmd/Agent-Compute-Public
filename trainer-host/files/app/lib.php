<?php
// Shared helpers for trainer.hohedoc.ir. Secrets live in ../secrets.php (outside public_html).
// Any ../secrets-extra*.php (same "return [...]" shape) is merged on top, so new keys can be
// added by uploading one small file (whatever exact name the panel gave it) instead of editing.
const TRAINER_ROOT = __DIR__ . '/..';

function trainer_secrets(): array {
    static $s = null;
    if ($s === null) {
        $s = [];
        $files = [TRAINER_ROOT . '/secrets.php'];
        foreach (glob(TRAINER_ROOT . '/secrets-extra*.php') ?: [] as $extra) $files[] = $extra;
        foreach ($files as $p) {
            if (is_file($p)) {
                $part = include $p;
                if (is_array($part)) $s = array_merge($s, array_filter($part, fn($v) => $v !== '' && $v !== null));
            }
        }
    }
    return $s;
}

function trainer_secret(string $key): string {
    return (string) (trainer_secrets()[$key] ?? '');
}

function trainer_state_path(string $name): string {
    $dir = TRAINER_ROOT . '/var';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir . '/' . $name;
}

// Repo-native door (1405-08-12 · orchestra github-native-doors, section C): no stored key. The caller pushes
// trainer-auth/<sha256(nonce)>.json ({"exp": unix-time, ≤26 h ahead}) to a BRANCH of the public compute repo and sends
// X-Trainer-Nonce + X-Trainer-Ref. ⛔ Only a real branch proves push access (flaw repo-auth-accepts-pull-request-refs):
// the GitHub API must confirm it. Read-only jobs only; 20 failures / 10 min close this path.
const TRAINER_AUTH_REPO = 'lili2001mua-cmd/Agent-Compute-Public';
function trainer_https_get(string $url, int $timeout = 20): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'], CURLOPT_USERAGENT => 'trainer-host']);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = $raw === false ? null : json_decode((string) $raw, true);
    return [$code, is_array($j) ? $j : null];
}
function trainer_ref_is_branch(string $ref): bool {
    if (!preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $ref) || strlen($ref) > 120 || str_contains($ref, '..')) return false;
    if (preg_match('#^(refs|pull)(/|$)#i', $ref) || preg_match('/^[0-9a-f]{7,40}$/i', $ref)) return false;
    $dir = trainer_state_path('auth'); if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $cache = "$dir/branch-" . hash('sha256', $ref);
    if ((int) @file_get_contents($cache) > time() - 3600) return true;
    [$code, $r] = trainer_https_get('https://api.github.com/repos/' . TRAINER_AUTH_REPO . '/branches/' . str_replace('%2F', '/', rawurlencode($ref)));
    if ($code !== 200 || !is_array($r) || ($r['name'] ?? '') !== $ref) return false;
    file_put_contents($cache, (string) time(), LOCK_EX);
    return true;
}
function trainer_repo_pass_ok(string $nonce, string $ref): bool {
    if ($nonce === '' && $ref === '') return false;
    $f = trainer_state_path('door-fail.json');
    $st = @json_decode((string) @file_get_contents($f), true) ?: ['n' => 0, 't' => 0];
    if (time() - (int) $st['t'] > 600) $st = ['n' => 0, 't' => time()];
    if ((int) $st['n'] >= 20) return false;
    if (preg_match('/^[a-f0-9]{64}$/', $nonce) && trainer_ref_is_branch($ref)) {
        $h = hash('sha256', $nonce);
        $dir = trainer_state_path('auth'); if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if ((int) @file_get_contents("$dir/$h") > time()) return true;
        [$code, $r] = trainer_https_get('https://raw.githubusercontent.com/' . TRAINER_AUTH_REPO . '/' . $ref . '/trainer-auth/' . $h . '.json');
        $exp = (int) (($r ?? [])['exp'] ?? 0);
        if ($code === 200 && $exp > time() && $exp <= time() + 26 * 3600) { file_put_contents("$dir/$h", (string) $exp, LOCK_EX); return true; }
    }
    $st['n'] = (int) $st['n'] + 1;
    file_put_contents($f, json_encode($st), LOCK_EX);
    return false;
}

function trainer_log(string $stream, string $line): void {
    file_put_contents(trainer_state_path($stream . '.log'), gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}
