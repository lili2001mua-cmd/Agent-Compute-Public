<?php
// Shared helpers for routine.hohedoc.ir — the holding's one clock for scheduled routines (user's rule
// 1405-08-07: time and execution live on the host, never on a Claude account).
// Secrets, all outside public_html and never printed:
//   ../keys/<alias>-<scope>.key  one per Claude account dispatcher (format: README.md)
//   ../secrets.php               GITHUB_READ_TOKEN (fine-grained, Contents: Read on the private repo only)
// TELEGRAM_BOT_TOKEN is borrowed from trainer's secret files, by the cron only (open_basedir hides siblings
// from the web side).
const R_ROOT = __DIR__ . '/..';
const R_ALERT_CHAT = -1004359416005;     // trainer control channel — the one the user already watches
const R_PRIVATE_REPO = 'lili2001mua-cmd/Agent-Project-Backup';
const R_ANTHROPIC_VERSION = '2023-06-01';
const R_DEFAULT_BETA = 'experimental-cc-routine';
const R_PACK_TTL = 72 * 3600;
// Countries the host must never fire from (account safety). Unknown country also blocks firing.
const R_BLOCKED_COUNTRIES = ['IR', 'CN', 'RU', 'KP', 'SY', 'CU', 'BY', 'VE', 'AF', 'MM'];

function r_var(string $name): string {
    $dir = R_ROOT . '/var';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir . '/' . $name;
}

function r_read_json(string $name): array {
    return @json_decode((string) @file_get_contents(r_var($name)), true) ?: [];
}

function r_write_json(string $name, array $data): void {
    $p = r_var($name);
    file_put_contents($p . '.tmp', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($p . '.tmp', $p);
}

function r_log(string $line): void {
    file_put_contents(r_var('routine.log'), gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function r_secret(string $key): string {
    static $s = null;
    if ($s === null) {
        $s = [];
        $p = R_ROOT . '/secrets.php';
        if (is_file($p)) { @chmod($p, 0600); $x = include $p; if (is_array($x)) $s = $x; }
    }
    return (string) ($s[$key] ?? '');
}

function r_jobs(): array {
    $j = @json_decode((string) @file_get_contents(__DIR__ . '/jobs.json'), true);
    return is_array($j) ? $j : ['jobs' => []];
}

function r_armed(): bool {
    // Firing is switched on from the repo (an auditable commit), never from the web.
    return trim((string) @file_get_contents(__DIR__ . '/dispatch.flag')) === 'armed';
}

// ── keys ─────────────────────────────────────────────────────────────────────────────────────────────
// Strict reader: the file is never include()d. Returns [alias => [scope => key]] plus a list of problems
// (alias-level only, never a value).
function r_parse_key(string $raw): array {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));
    if (!$lines || trim($lines[0]) !== 'rtk1') return ['error' => 'first line must be rtk1'];
    $k = [];
    foreach (array_slice($lines, 1) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#') continue;
        if (!preg_match('/^([a-z]+)\s*:\s*(.+)$/', $l, $m)) return ['error' => 'bad line'];
        $k[$m[1]] = trim($m[2]);
    }
    if (!preg_match('/^acct-[a-z0-9]{1,12}$/', $k['alias'] ?? '')) return ['error' => 'bad alias'];
    if (!in_array($k['scope'] ?? '', ['repo', 'drive'], true)) return ['error' => 'bad scope'];
    if (!preg_match('#^https://api\.anthropic\.com/[A-Za-z0-9/_.\-]+$#', $k['url'] ?? '')) return ['error' => 'bad url'];
    if (!preg_match('/^\S{20,}$/', $k['token'] ?? '')) return ['error' => 'bad token'];
    if (isset($k['beta']) && !preg_match('/^[a-z0-9\-]{0,64}$/', $k['beta'])) return ['error' => 'bad beta'];
    return ['key' => ['alias' => $k['alias'], 'scope' => $k['scope'], 'url' => $k['url'], 'token' => $k['token'],
                      'beta' => $k['beta'] ?? R_DEFAULT_BETA]];
}

function r_keys(): array {
    $out = ['keys' => [], 'problems' => []];
    // A key under public_html would be served as plain text: refuse it and say so.
    foreach (glob(R_ROOT . '/public_html/{,*/}*.key', GLOB_BRACE) ?: [] as $bad) {
        $out['problems'][] = 'a .key file is inside public_html — move it to keys/ (not used)';
    }
    $dir = R_ROOT . '/keys';
    if (!is_dir($dir)) return $out;
    @chmod($dir, 0700);
    foreach (glob($dir . '/*.key') ?: [] as $p) {
        @chmod($p, 0600);
        $name = basename($p, '.key');
        $r = r_parse_key((string) file_get_contents($p));
        if (isset($r['error'])) { $out['problems'][] = "$name: " . $r['error']; continue; }
        $k = $r['key'];
        if ("{$k['alias']}-{$k['scope']}" !== $name) { $out['problems'][] = "$name: file name must be alias-scope.key"; continue; }
        $out['keys'][$k['alias']][$k['scope']] = $k;
    }
    return $out;
}

// ── telegram (with an outbox: a failed send is retried on the next tick, never dropped) ─────────────
function r_bot_token(): string {
    $dir = dirname((string) realpath(R_ROOT)) . '/trainer.hohedoc.ir';
    $files = [$dir . '/secrets.php'];
    foreach (glob($dir . '/secrets-extra*.php') ?: [] as $x) $files[] = $x;
    foreach (array_reverse($files) as $p) {
        if (!@is_file($p)) continue;
        $t = include $p;
        if (is_array($t) && !empty($t['TELEGRAM_BOT_TOKEN'])) return (string) $t['TELEGRAM_BOT_TOKEN'];
    }
    return '';
}

function r_telegram_send(string $text): bool {
    $token = r_bot_token();
    if ($token === '') return false;
    $ch = curl_init("https://api.telegram.org/bot$token/sendMessage");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['chat_id' => R_ALERT_CHAT, 'text' => mb_substr($text, 0, 4096),
                               'link_preview_options' => json_encode(['is_disabled' => true])]]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $body !== false && $code === 200;
}

function r_notify(string $text): void {
    $box = r_read_json('outbox.json');
    $box[] = ['text' => $text, 'at' => time()];
    r_write_json('outbox.json', $box);
    r_flush_outbox();
}

function r_flush_outbox(): void {
    $box = r_read_json('outbox.json');
    if (!$box) return;
    $left = [];
    foreach ($box as $m) {
        if (!r_telegram_send($m['text'])) { $left[] = $m; r_log('telegram-failed (kept in outbox)'); }
    }
    r_write_json('outbox.json', $left);
}

// ── time ─────────────────────────────────────────────────────────────────────────────────────────────
// Five-field cron, evaluated in UTC (jobs.json carries cron_utc; the Tehran time sits beside it).
function r_cron_field(string $f, int $v, int $min, int $max): bool {
    foreach (explode(',', $f) as $part) {
        $step = 1;
        if (str_contains($part, '/')) { [$part, $s] = explode('/', $part, 2); $step = max(1, (int) $s); }
        if ($part === '*') { $lo = $min; $hi = $max; }
        elseif (str_contains($part, '-')) { [$lo, $hi] = array_map('intval', explode('-', $part, 2)); }
        else { $lo = (int) $part; $hi = ($step > 1) ? $max : $lo; }
        if ($v >= $lo && $v <= $hi && (($v - $lo) % $step) === 0) return true;
    }
    return false;
}

function r_cron_match(string $cron, int $ts): bool {
    $f = preg_split('/\s+/', trim($cron));
    if (count($f) !== 5) return false;
    [$mi, $h, $dom, $mon, $dow] = $f;
    $d = (int) gmdate('w', $ts);
    return r_cron_field($mi, (int) gmdate('i', $ts), 0, 59) && r_cron_field($h, (int) gmdate('G', $ts), 0, 23)
        && r_cron_field($dom, (int) gmdate('j', $ts), 1, 31) && r_cron_field($mon, (int) gmdate('n', $ts), 1, 12)
        && (r_cron_field($dow, $d, 0, 6) || ($d === 0 && r_cron_field($dow, 7, 0, 7)));
}

// Latest matching minute in (now - window, now], or 0.
function r_last_slot(string $cron, int $now, int $windowMin): int {
    $t = $now - ($now % 60);
    for ($i = 0; $i < $windowMin; $i++, $t -= 60) if (r_cron_match($cron, $t)) return $t;
    return 0;
}

function r_next_slot(string $cron, int $now): int {
    $t = $now - ($now % 60) + 60;
    for ($i = 0; $i < 60 * 24 * 40; $i++, $t += 60) if (r_cron_match($cron, $t)) return $t;
    return 0;
}

function r_tehran(int $ts, string $fmt = 'Y-m-d H:i'): string {
    $d = new DateTime('@' . $ts);
    $d->setTimezone(new DateTimeZone('Asia/Tehran'));
    return $d->format($fmt);
}

function r_run_id(string $job, int $slot): string {
    return $job . '-' . r_tehran($slot, 'Ymd-Hi');
}
