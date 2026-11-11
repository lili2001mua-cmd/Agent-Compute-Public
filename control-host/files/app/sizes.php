<?php
// «حجمِ واقعی» of every store the app lists under زیرساخت ← حافظه‌ها (user ask 1405-08-20, app v47).
// Built by the 5-minute cron (tick.php) into var/sizes.json; GET api.php?op=sizes serves it. Never written by a session.
//   repos  — GitHub's own `size` of each repository (KB on GitHub's disk), via the vault token · every 60 min
//   drive  — about.storageQuota of every Drive account the studio holds (var/drive-accounts/*.json) · every 60 min
//   hosts  — Germany: bytes on disk of this server account (du, else a PHP walk), per domain too · every 6 h
//            Iran:    relay.hohe1.ir/run.php?job=disk (cached there for 6 h, number only) · every 6 h
// Each part keeps its own «measured at»; a failed part keeps its last good value and carries the error next to it.
require_once __DIR__ . '/lib.php';

const SZ_REPOS = ['Agent-Project-Backup', 'Agent-Compute-Public', 'stylistcrm', 'stylistdirectory',
                  'Gemini-Agent-Backup', 'ChatGPT-Agent-Backup'];
const SZ_FAST = 3600;        // repos + drive
const SZ_SLOW = 6 * 3600;    // host disks
const SZ_RETRY = 1800;       // a host that failed (e.g. the relay before its disk job landed) is retried after 30 min, not 6 h
const SZ_RELAY = 'https://relay.hohe1.ir/run.php?job=disk';

function sz_owner(): string { return explode('/', CTL_REPO)[0]; }

/** Bytes under $dir: `du -sk` when the host allows it, else a PHP walk with a time cap. [bytes|null, method, files|null] */
function sz_dir_bytes(string $dir, int $capSeconds = 240): array {
    if (!is_dir($dir)) return [null, 'missing', null];
    $dis = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (function_exists('shell_exec') && !in_array('shell_exec', $dis, true)) {
        $o = @shell_exec('du -sk ' . escapeshellarg($dir) . ' 2>/dev/null');
        if (is_string($o) && preg_match('/^(\d+)\s/', $o, $m)) return [(int) $m[1] * 1024, 'du', null];
    }
    $t0 = time(); $sum = 0; $n = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) {
            if ($f->isLink()) continue;
            if ($f->isFile()) { $sum += $f->getSize(); $n++; }
            if (($n & 1023) === 0 && time() - $t0 > $capSeconds) return [$sum, 'walk-partial', $n];
        }
    } catch (Throwable $e) { return [$sum ?: null, 'walk-error', $n]; }
    return [$sum, 'walk', $n];
}

function sz_repos(): array {
    $out = [];
    foreach (SZ_REPOS as $r) {
        [$c, $j] = gh('GET', '/repos/' . sz_owner() . '/' . $r);
        $out[] = $c === 200
            ? ['name' => $r, 'bytes' => (int) ($j['size'] ?? 0) * 1024, 'private' => (bool) ($j['private'] ?? false),
               'pushed_at' => $j['pushed_at'] ?? null]
            : ['name' => $r, 'bytes' => null, 'error' => $c === 404 ? 'token has no access' : 'github ' . $c];
    }
    return $out;
}

/** One access token per Drive account of the studio (same server account; same files board_drive_token reads). */
function sz_drive_accounts(): array {
    $root = realpath(CTL_ROOT) ?: CTL_ROOT;
    $var = dirname($root) . '/studio.hohedoc.ir/var';
    $c = @json_decode((string) @file_get_contents($var . '/drive-web-client.json'), true);
    $files = glob($var . '/drive-accounts/*.json') ?: [];
    if (!is_array($c) || empty($c['id']) || empty($c['secret'])) return [['account' => '—', 'error' => 'studio drive client missing']];
    $out = [];
    foreach ($files as $f) {
        $acc = basename($f, '.json');
        $a = @json_decode((string) @file_get_contents($f), true);
        if (!is_array($a) || empty($a['refresh_token'])) { $out[] = ['account' => $acc, 'error' => 'not connected']; continue; }
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query(['client_id' => $c['id'], 'client_secret' => $c['secret'],
                'refresh_token' => $a['refresh_token'], 'grant_type' => 'refresh_token'])]);
        $r = json_decode((string) curl_exec($ch), true); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code !== 200 || empty($r['access_token'])) { $out[] = ['account' => $acc, 'error' => 'token ' . $code]; continue; }
        $ch = curl_init('https://www.googleapis.com/drive/v3/about?fields=storageQuota');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $r['access_token']]]);
        $q = json_decode((string) curl_exec($ch), true); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $s = $q['storageQuota'] ?? null;
        $out[] = ($code === 200 && is_array($s))
            ? ['account' => $acc, 'bytes' => (int) ($s['usage'] ?? 0), 'limit' => isset($s['limit']) ? (int) $s['limit'] : null,
               'in_drive' => isset($s['usageInDrive']) ? (int) $s['usageInDrive'] : null,
               'in_trash' => isset($s['usageInDriveTrash']) ? (int) $s['usageInDriveTrash'] : null]
            : ['account' => $acc, 'error' => 'drive ' . $code];
    }
    return $out ?: [['account' => '—', 'error' => 'no drive account on studio']];
}

function sz_germany(): array {
    $root = realpath(CTL_ROOT) ?: CTL_ROOT;          // …/domains/control.hohedoc.ir
    $domains = dirname($root);                        // …/domains
    $home = dirname($root, 2);                        // …/home/<user>
    [$b, $how] = sz_dir_bytes($home);
    $per = [];
    foreach (glob($domains . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        [$db] = sz_dir_bytes($d, 60);
        $per[] = ['name' => basename($d), 'bytes' => $db];
    }
    usort($per, fn($x, $y) => ($y['bytes'] ?? 0) <=> ($x['bytes'] ?? 0));
    return ['id' => 'germany', 'domain' => 'hohedoc.ir', 'bytes' => $b, 'method' => $how,
            'disk_free' => @disk_free_space($home) ?: null, 'disk_total' => @disk_total_space($home) ?: null, 'parts' => $per];
}

function sz_iran(): array {
    $ch = curl_init(SZ_RELAY);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 200]);
    $j = json_decode((string) curl_exec($ch), true); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !is_array($j) || !($j['ok'] ?? false))
        return ['id' => 'iran', 'domain' => 'hohe1.ir', 'bytes' => null,
                'error' => $code === 400 ? 'relay has no disk job yet' : 'relay ' . $code];
    return ['id' => 'iran', 'domain' => 'hohe1.ir', 'bytes' => $j['bytes'] ?? null, 'method' => $j['method'] ?? null,
            'measured' => $j['at'] ?? null, 'parts' => $j['parts'] ?? []];
}

/** Keep a part's last good value when a fresh try fails; the error rides next to it. */
function sz_merge(array $old, array $new, string $key): array {
    $prev = [];
    foreach ($old as $o) $prev[$o[$key] ?? ''] = $o;
    foreach ($new as &$n) {
        $p = $prev[$n[$key] ?? ''] ?? null;
        if (($n['bytes'] ?? null) === null && $p && ($p['bytes'] ?? null) !== null) $n = ['bytes' => $p['bytes'], 'at' => $p['at'] ?? null] + $n;
        elseif (($n['bytes'] ?? null) !== null) $n['at'] = time();
    }
    return $new;
}

function sizes_tick(): void {
    $lock = @fopen(ctl_var('sizes.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    $s = ctl_json_read('sizes.json', []);
    $now = time();
    if ($now - (int) ($s['fast_at'] ?? 0) >= SZ_FAST) {
        $s['repos'] = sz_merge($s['repos'] ?? [], sz_repos(), 'name');
        $s['drives'] = sz_merge($s['drives'] ?? [], sz_drive_accounts(), 'account');
        $s['fast_at'] = $now;
        ctl_json_write('sizes.json', $s + ['period_fast' => SZ_FAST, 'period_slow' => SZ_SLOW]);
    }
    $failed = [];
    foreach (($s['hosts'] ?? []) as $h) if (($h['bytes'] ?? null) === null) $failed[] = $h['id'] ?? '';
    if ($now - (int) ($s['slow_at'] ?? 0) >= SZ_SLOW) {
        @set_time_limit(900);
        $s['hosts'] = sz_merge($s['hosts'] ?? [], [sz_germany(), sz_iran()], 'id');
        $s['slow_at'] = time();
    } elseif ($failed && $now - (int) ($s['retry_at'] ?? $s['slow_at'] ?? 0) >= SZ_RETRY) {
        // only the failed host is measured again; slow_at (the 6-hour clock of the good ones) stays as it is
        @set_time_limit(900);
        $out = [];
        foreach (($s['hosts'] ?? []) as $h) $out[] = in_array($h['id'] ?? '', $failed, true)
            ? sz_merge([$h], [($h['id'] ?? '') === 'iran' ? sz_iran() : sz_germany()], 'id')[0] : $h;
        $s['hosts'] = $out;
        $s['retry_at'] = time();
    }
    $s['period_fast'] = SZ_FAST; $s['period_slow'] = SZ_SLOW;
    ctl_json_write('sizes.json', $s);
    flock($lock, LOCK_UN);
}
