<?php
// «حجمِ واقعی» و فهرستِ زندهٔ حافظه‌های هلدینگ (مخازنِ گیت‌هاب، هاست‌های متصل، گوگل‌درایو و تلگرام).
// Built by the 5-minute cron (tick.php) into var/sizes.json; GET api.php?op=sizes serves it. Never written by a session.
//   repos    — auto-discovered from GitHub /user/repos (name, size, brief Persian summary) · every 60 min
//   drive    — about.storageQuota of every Drive account the studio holds (var/drive-accounts/*.json) · every 60 min
//   telegram — connected Telegram bridge + live channel list from tg.hohedoc.ir/var/chain-chats.json · every 60 min
//   hosts    — Germany (live domains/ scan), Iran relay (liliumesorkh.ir), and Iran Stylist host (redlilium.ir + sites.php) · every 6 h
// Each part keeps its own «measured at»; a failed part keeps its last good value and carries the error next to it.
require_once __DIR__ . '/lib.php';

const SZ_SCHEMA_V = 2;
const SZ_FALLBACK_REPOS = ['Agent-Project-Backup', 'Agent-Compute-Public', 'stylistcrm', 'stylistdirectory',
                           'Gemini-Agent-Backup', 'ChatGPT-Agent-Backup'];
const SZ_REPO_SUMMARIES = [
    'Agent-Project-Backup'  => 'مخزنِ مرکزیِ هلدینگ، ایجنت‌ها، قوانین و دفترچه‌های پروژه',
    'Agent-Compute-Public'  => 'سرویس‌های هاستِ آلمان و رلهٔ ایران (کنترل، سایت‌ها، اپ‌ها، تلگرام)',
    'stylistcrm'            => 'نرم‌افزارِ مدیریت و حسابداریِ سالن‌های زیبایی (PWA و Hub)',
    'stylistdirectory'      => 'دایرکتوری و وب‌سایتِ معرفیِ سالن‌های استایلیست',
    'Gemini-Agent-Backup'   => 'فضای کار، ابزارها و پل‌های اختصاصیِ کارمندِ Gemini',
    'ChatGPT-Agent-Backup'  => 'فضای کار و دستورالعمل‌های اختصاصیِ کارمندِ ChatGPT',
    'Codex-Agent-Backup'    => 'فضای کار و دستورالعمل‌های اختصاصیِ کارمندِ Codex',
    'Grok-Agent-Backup'     => 'فضای کار و دستورالعمل‌های اختصاصیِ کارمندِ Grok',
    'Claude-Project-Backup' => 'بایگانیِ تاریخچهٔ نسلِ اولِ مخزنِ مرکزیِ هلدینگ',
];
const SZ_FAST = 3600;        // repos + drive + telegram
const SZ_SLOW = 6 * 3600;    // host disks
const SZ_RETRY = 1800;       // a host that failed is retried after 30 min, not 6 h
const SZ_RELAY = 'https://relay.liliumesorkh.ir/run.php?job=disk';

function sz_owner(): string { return explode('/', CTL_REPO)[0]; }

function sz_repo_summary(string $name, ?string $ghDesc): string {
    if (isset(SZ_REPO_SUMMARIES[$name])) return SZ_REPO_SUMMARIES[$name];
    $d = trim((string) $ghDesc);
    if ($d !== '') return mb_substr($d, 0, 90);
    return 'مخزنِ گیت‌هابِ هلدینگ';
}

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

/** Auto-discover all owner repositories from GitHub API; falls back to SZ_FALLBACK_REPOS if listing fails. */
function sz_repos(): array {
    $out = [];
    $seen = [];
    [$lc, $list] = gh('GET', '/user/repos?per_page=100&affiliation=owner&sort=updated');
    if ($lc === 200 && is_array($list)) {
        foreach ($list as $j) {
            if (!is_array($j) || empty($j['name'])) continue;
            $name = (string) $j['name'];
            $seen[$name] = true;
            $out[] = [
                'name'      => $name,
                'bytes'     => (int) ($j['size'] ?? 0) * 1024,
                'private'   => (bool) ($j['private'] ?? false),
                'pushed_at' => $j['pushed_at'] ?? null,
                'summary'   => sz_repo_summary($name, $j['description'] ?? null),
            ];
        }
    }
    if (!$out) {
        foreach (SZ_FALLBACK_REPOS as $r) {
            [$c, $j] = gh('GET', '/repos/' . sz_owner() . '/' . $r);
            $out[] = $c === 200
                ? ['name' => $r, 'bytes' => (int) ($j['size'] ?? 0) * 1024, 'private' => (bool) ($j['private'] ?? false),
                   'pushed_at' => $j['pushed_at'] ?? null, 'summary' => sz_repo_summary($r, $j['description'] ?? null)]
                : ['name' => $r, 'bytes' => null, 'summary' => sz_repo_summary($r, null),
                   'error' => $c === 404 ? 'token has no access' : 'github ' . $c];
        }
    }
    usort($out, fn($a, $b) => ($b['bytes'] ?? -1) <=> ($a['bytes'] ?? -1));
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

/** Auto-discover domains from sibling sites.hohedoc.ir/app/sites.php grouped by root domain. */
function sz_sites_domains(): array {
    $root = realpath(CTL_ROOT) ?: CTL_ROOT;
    $sitesFile = dirname($root) . '/sites.hohedoc.ir/app/sites.php';
    if (!is_file($sitesFile)) return [];
    $cfg = @include $sitesFile;
    if (!is_array($cfg)) return [];
    $byRoot = [];
    foreach ($cfg as $site) {
        if (!is_array($site) || !is_array($site['envs'] ?? null)) continue;
        foreach ($site['envs'] as $url) {
            $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
            if ($host === '') continue;
            $parts = explode('.', $host);
            $rootDom = count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $host;
            $byRoot[$rootDom][$host] = true;
        }
    }
    $res = [];
    foreach ($byRoot as $r => $map) $res[$r] = array_keys($map);
    return $res;
}

function sz_germany(): array {
    $root = realpath(CTL_ROOT) ?: CTL_ROOT;          // …/domains/control.hohedoc.ir
    $domains = dirname($root);                        // …/domains
    $home = dirname($root, 2);                        // …/home/<user>
    [$b, $how] = sz_dir_bytes($home);
    $per = [];
    $subs = [];
    foreach (glob($domains . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $name = basename($d);
        [$db] = sz_dir_bytes($d, 60);
        $per[] = ['name' => $name, 'bytes' => $db];
        $subs[] = $name;
    }
    usort($per, fn($x, $y) => ($y['bytes'] ?? 0) <=> ($x['bytes'] ?? 0));
    sort($subs);
    return [
        'id'         => 'germany',
        'title'      => 'هاستِ آلمان',
        'domain'     => 'hohedoc.ir',
        'role'       => 'مغزِ کارهای خودکار، سرویس‌های کنترل، سایت‌ها، اپ‌ها و تلگرام',
        'full_width' => true,
        'bytes'      => $b,
        'method'     => $how,
        'disk_free'  => @disk_free_space($home) ?: null,
        'disk_total' => @disk_total_space($home) ?: null,
        'parts'      => $per,
        'subdomains' => $subs,
    ];
}

function sz_iran(array $sitesMap = []): array {
    $subs = array_values(array_unique(array_merge(['relay.liliumesorkh.ir'], $sitesMap['liliumesorkh.ir'] ?? ['liliumesorkh.ir'])));
    sort($subs);
    $ch = curl_init(SZ_RELAY);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 200]);
    $j = json_decode((string) curl_exec($ch), true); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $base = [
        'id'         => 'iran',
        'title'      => 'هاستِ ایران — رله و لیلیوم',
        'domain'     => 'liliumesorkh.ir',
        'role'       => 'رلهٔ داخلیِ اپ، نگهبانِ قطعی و خواندنِ سایت‌های ایرانی',
        'full_width' => false,
        'subdomains' => $subs,
    ];
    if ($code !== 200 || !is_array($j) || !($j['ok'] ?? false)) {
        return $base + ['bytes' => null, 'error' => $code === 400 ? 'relay has no disk job yet' : 'relay ' . $code];
    }
    return $base + ['bytes' => $j['bytes'] ?? null, 'method' => $j['method'] ?? null,
                    'measured' => $j['at'] ?? null, 'parts' => $j['parts'] ?? []];
}

function sz_stylist_host(array $sitesMap = []): array {
    $subs = array_values(array_unique(array_merge(
        $sitesMap['redlilium.ir'] ?? ['pwa.redlilium.ir', 'hub.redlilium.ir', 'help.redlilium.ir'],
        $sitesMap['stylistdirectory.ir'] ?? ['stylistdirectory.ir']
    )));
    sort($subs);
    return [
        'id'         => 'stylist',
        'title'      => 'هاستِ ایران — استایلیست',
        'domain'     => 'redlilium.ir',
        'role'       => 'هاستِ اصلیِ سرویس‌های استایلیست (PWA، Hub، راهنما و دایرکتوری)',
        'full_width' => false,
        'bytes'      => null,
        'parts'      => [],
        'subdomains' => $subs,
    ];
}

/** Auto-discover connected Telegram bridge and active channels from sibling tg.hohedoc.ir/var/chain-chats.json. */
function sz_telegram(): array {
    $root = realpath(CTL_ROOT) ?: CTL_ROOT;
    $tgVar = dirname($root) . '/tg.hohedoc.ir/var';
    $chatsFile = $tgVar . '/chain-chats.json';
    $channels = [];
    $seen = [];
    if (is_file($chatsFile)) {
        $j = @json_decode((string) @file_get_contents($chatsFile), true);
        if (is_array($j['chats'] ?? null)) {
            foreach ($j['chats'] as $id => $meta) {
                if (!is_array($meta)) continue;
                $title = trim((string) ($meta['title'] ?? $meta['name'] ?? ''));
                if ($title === '') $title = 'کانال ' . $id;
                $kind = trim((string) ($meta['kind'] ?? 'channel'));
                $channels[] = ['id' => (string) $id, 'title' => $title, 'kind' => $kind];
                $seen[mb_strtolower($title)] = true;
            }
        }
    }
    // Always include active weekly/manual DB backup channel if not already in chain-chats.json
    if (!isset($seen['backup database redlilium'])) {
        $channels[] = ['id' => '-1004449841183', 'title' => 'Backup Database redlilium', 'kind' => 'backup'];
    }
    return [
        'account'   => 'tg.hohedoc.ir',
        'connected' => is_dir($tgVar),
        'summary'   => 'اکانت و درگاهِ تلگرامِ هلدینگ برای بکاپِ دیتابیس، استخرِ محتوا، هشدارهای سیستمی و کانال‌های عملیاتی',
        'channels'  => $channels,
        'at'        => time(),
    ];
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
    $upgrade = ((int) ($s['schema_v'] ?? 0)) < SZ_SCHEMA_V;
    if ($upgrade || $now - (int) ($s['fast_at'] ?? 0) >= SZ_FAST) {
        $s['repos'] = sz_merge($s['repos'] ?? [], sz_repos(), 'name');
        $s['drives'] = sz_merge($s['drives'] ?? [], sz_drive_accounts(), 'account');
        $s['telegram'] = sz_telegram();
        $s['fast_at'] = $now;
        $s['schema_v'] = SZ_SCHEMA_V;
        ctl_json_write('sizes.json', $s + ['period_fast' => SZ_FAST, 'period_slow' => SZ_SLOW]);
    }
    $failed = [];
    foreach (($s['hosts'] ?? []) as $h) if (($h['bytes'] ?? null) === null && ($h['id'] ?? '') !== 'stylist') $failed[] = $h['id'] ?? '';
    if ($upgrade || $now - (int) ($s['slow_at'] ?? 0) >= SZ_SLOW) {
        @set_time_limit(900);
        $sitesMap = sz_sites_domains();
        $s['hosts'] = sz_merge($s['hosts'] ?? [], [sz_germany(), sz_iran($sitesMap), sz_stylist_host($sitesMap)], 'id');
        $s['slow_at'] = time();
    } elseif ($failed && $now - (int) ($s['retry_at'] ?? $s['slow_at'] ?? 0) >= SZ_RETRY) {
        @set_time_limit(900);
        $sitesMap = sz_sites_domains();
        $out = [];
        foreach (($s['hosts'] ?? []) as $h) {
            $hid = $h['id'] ?? '';
            if (in_array($hid, $failed, true)) {
                $fresh = $hid === 'iran' ? sz_iran($sitesMap) : sz_germany();
                $out[] = sz_merge([$h], [$fresh], 'id')[0];
            } else {
                $out[] = $h;
            }
        }
        $s['hosts'] = $out;
        $s['retry_at'] = time();
    }
    $s['period_fast'] = SZ_FAST; $s['period_slow'] = SZ_SLOW; $s['schema_v'] = SZ_SCHEMA_V;
    ctl_json_write('sizes.json', $s);
    flock($lock, LOCK_UN);
}
