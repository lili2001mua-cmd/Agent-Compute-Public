<?php
// Trainer project-control board, computed on this host (user decision 1405-08-12: every project-control state
// file lives on control.hohedoc.ir, nothing in git; no key in any Claude account; no Actions minute).
// Port of Agent-Project-Backup/Sys.scripts/step3-board-build.py: the step of every bundle comes from the
// trainer's own records in the private repo (read with the vault token), never from a file a session writes here.
//   1. mapping ledger      Memory-Workflow/mapping-ledger/ledger.json      → population
//   2. probes              Memory-Workflow/upgrade-adoption/probes.yaml    → step 3.6
//   3. close runs          Memory-Workflow/close-package/_runs.md + docs/فهرست-ویدیوها.md → step 4
//   4. feedback / pool     Memory-Workflow/step3-feedback/ , intake-pool/state/pool-contents.json → flags
//   5. sessions            reports/<key>.md head (session_url, origin) + the commit that added the ledger row
// Output: var/trainer-board/board.json (read by the app through api.php?op=trainer-board).
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/toggle.php';      // ctl_jalali()

const BOARD_DIR = 'trainer-board';
const BOARD_COMMITS_PER_TICK = 40;         // first run walks the ledger history in slices; later ticks see only new commits

function board_var(string $name): string {
    $d = ctl_var(BOARD_DIR);
    if (!is_dir($d)) mkdir($d, 0700, true);
    return $d . '/' . $name;
}
function board_read(string $n, $def) { $x = @json_decode((string) @file_get_contents(board_var($n)), true); return is_array($x) ? $x : $def; }
function board_write(string $n, $data): void {
    $f = board_var($n);
    file_put_contents($f . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($f . '.tmp', $f);
}

/** Raw file text from main (works above the 1 MB limit of the JSON contents API). */
function gh_raw(string $path): ?string {
    $ch = curl_init('https://api.github.com/repos/' . CTL_REPO . '/contents/' . rawurlencode_path($path) . '?ref=' . CTL_BRANCH);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . ctl_gh_token(), 'Accept: application/vnd.github.raw', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: control-hohedoc']]);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($code === 200 && is_string($raw)) ? $raw : null;
}

/** probes.yaml is a flat list; bundle, test_result and the Jalali creation day (to drop a past cycle's probes). */
function board_probes(string $yaml): array {
    $out = [];
    foreach (preg_split('/^  - /m', $yaml) as $i => $blk) {
        if ($i === 0) continue;
        preg_match('/^\s*bundle:\s*"?([^"\n]+)"?/m', $blk, $b);
        preg_match('/^\s*test_result:\s*"?([^"\n]*)"?/m', $blk, $t);
        preg_match('/^\s*created:\s*"?([^"\n]*)"?/m', $blk, $c);
        // ⛔ 1405-08-19: latest run time (test_run_utc / last_run_utc). A re-run of step 3.6 on a probe that already passed
        //    changed nothing on the board, so the app's «اجرا» spinner never stopped (it waits for the bundle signature to
        //    change). The run time rides inside step36_result, so every run — even a re-run — changes the signature.
        $ts = 0;
        foreach (['test_run_utc', 'last_run_utc'] as $f)
            if (preg_match('/^\s*' . $f . ':\s*"?([0-9T:\-Z]+)"?/m', $blk, $m)) $ts = max($ts, (int) strtotime($m[1]));
        if (!empty($b[1])) $out[trim($b[1])][] = ['r' => trim($t[1] ?? ''), 'd' => board_jdigits(trim($c[1] ?? '')), 't' => $ts];
    }
    return $out;
}

/** «۱۴۰۵-۰۸-۰۲» ⇒ «1405-08-02» (ledger rows and probes write Jalali days in either digit set). */
/** HH:MM in Tehran time with Persian digits (UTC+3:30; Iran has no DST). */
function board_tehran_hm(int $ts): string {
    return strtr(gmdate('H:i', $ts + 12600), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

function board_jdigits(string $s): string { return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']); }

function board_head(string $md): array {
    if (!preg_match('/^---\n(.*?)\n---/s', $md, $m)) return [];
    preg_match_all('/^(\w+):\s*(\S+)/m', $m[1], $kv, PREG_SET_ORDER);
    $h = []; foreach ($kv as $x) $h[$x[1]] = $x[2];
    return $h;
}

/** Walk the ledger's commit history (cached by sha): which session first added each key. */
function board_ledger_sessions(): array {
    $c = board_read('ledger-commits.json', ['done' => [], 'keys' => []]);
    $list = [];
    for ($page = 1; $page <= 5; $page++) {
        [$code, $j] = gh('GET', '/repos/' . CTL_REPO . '/commits?path=' . rawurlencode('Memory-Workflow/mapping-ledger/ledger.json') . '&per_page=100&page=' . $page);
        if ($code !== 200 || !is_array($j) || !$j) break;
        foreach ($j as $x) $list[] = ['sha' => $x['sha'], 'msg' => $x['commit']['message'] ?? '', 'at' => $x['commit']['committer']['date'] ?? ''];
        if (count($j) < 100) break;
    }
    $list = array_reverse($list);                          // oldest first: the first adder wins
    $n = 0;
    foreach ($list as $x) {
        if (isset($c['done'][$x['sha']])) continue;
        if ($n++ >= BOARD_COMMITS_PER_TICK) break;
        [$code, $d] = gh('GET', '/repos/' . CTL_REPO . '/commits/' . $x['sha']);
        if ($code !== 200) break;
        preg_match('#(https://claude\.ai/code/session_\w+)#', $x['msg'], $s);
        foreach (($d['files'] ?? []) as $f) {
            if (($f['filename'] ?? '') !== 'Memory-Workflow/mapping-ledger/ledger.json') continue;
            preg_match_all('/^\+.*"key":\s*"([^"]+)"/m', (string) ($f['patch'] ?? ''), $k);
            foreach (array_unique($k[1]) as $key) {
                $c['keys'][$key] ??= [];
                $c['keys'][$key][] = ['s' => $s[1] ?? null, 'at' => $x['at']];
            }
        }
        $c['done'][$x['sha']] = 1;
    }
    board_write('ledger-commits.json', $c);
    return $c['keys'];
}

/** First commit (any path) whose message names the key and carries a session other than the night one.
 *  The step-3 session's own commits name the bundle, so this finds the session the user opened without any
 *  file being written for it. Cached 30 minutes per key (search API: 30 calls a minute). */
function board_user_session(string $key, string $night): ?string {
    $cache = board_read('user-sessions.json', []);
    $c = $cache[$key] ?? null;
    // نتیجهٔ یافته‌شده ۳۰ دقیقه کش می‌شود؛ نتیجهٔ خالی فقط ۲ دقیقه، تا بستهٔ تازه‌کارشده نیم‌ساعت روی ۳ گیر نکند
    if ($c && !empty($c['s']) && time() - ($c['at'] ?? 0) < 1800) return $c['s'];
    if ($c && empty($c['s']) && time() - ($c['at'] ?? 0) < 120) return null;
    [$code, $j] = gh('GET', '/search/commits?q=' . rawurlencode('repo:' . CTL_REPO . ' ' . $key) . '&sort=committer-date&order=asc&per_page=50');
    if ($code !== 200) return $c['s'] ?? null;
    $s = null; $since = ''; $work = 0;
    foreach (($j['items'] ?? []) as $it) {
        $msg = (string) ($it['commit']['message'] ?? '');
        if (preg_match('#(https://claude\.ai/code/session_\w+)#', $msg, $m) && $m[1] !== $night) {
            if ($s === null) { $s = $m[1]; $since = (string) ($it['commit']['committer']['date'] ?? ''); }
            // «کارِ واقعی»: هر کامیتِ کاربر که فقط بازکردنِ سشن نباشد (نگاشت، کاوشگر، بستن…) — مبنای «کاربر کار کرده؟»
            if (!preg_match('/باز شد|تابلوی گامِ ۳|مرج/u', $msg)) $work++;
        }
    }
    $cache[$key] = ['s' => $s, 'since' => $since, 'work' => $work, 'at' => time()];
    board_write('user-sessions.json', $cache);
    return $s;
}

/** Persian label of a mapping-tree leaf (Agents-General/Assistant-Trainer/skill-trainer/mapping-specialist/references/mapping-decision-tree.md). */
function board_leaf(string $l): string {
    if ($l === '') return 'گره ثبت نشده';
    $fixed = ['L0-encyclopedia' => 'گرهِ ۰ — مالِ این هلدینگ نیست', 'L0.5-not-runnable' => 'گرهِ ۰٫۵ — برای کاربر اجراپذیر نیست',
              'L1-not-entering' => 'گرهِ ۱ — وارد نمی‌شود', 'L2-upgrade-existing' => 'گرهِ ۲ — ارتقای رکوردِ موجود',
              'L2-conflict' => 'گرهِ ۲ — با رکوردِ موجود تناقض دارد', 'L2.5-new-topic' => 'گرهِ ۲٫۵ — زیرشاخهٔ تازه'];
    if (isset($fixed[$l])) return $fixed[$l];
    if (preg_match('/^L4-(.+)$/u', $l, $m)) return 'گرهِ ۴ — کارِ تکراری: ' . $m[1];
    if (preg_match('/^L6-(.+)$/u', $l, $m)) return 'گرهِ ۶ — ابزار: ' . str_replace('-', ' ', $m[1]);
    return $l;
}

/** A clean short name for a mapping row, taken from the first clause of its point (same rule as the bundle title). */
function board_pname(string $point): string {
    $nm = $point;
    foreach ([':', '؛', ' — ', '،', '('] as $sep) $nm = explode($sep, $nm)[0];
    return mb_substr(trim($nm), 0, 42);
}

/** Where a mapping row landed, in plain words. */
function board_dest(string $d): string {
    if ($d === '') return 'جایی ذخیره نشد';
    if (preg_match('#^Agents-Utility/Knowledge/([^/]+)/?(.*)$#u', $d, $m)) return 'بانکِ دانشیِ ' . $m[1] . ($m[2] !== '' ? ' · ' . preg_replace('/\.md$/', '', basename($m[2])) : '');
    if (preg_match('#/([^/]+)/SKILL\.md$#u', $d, $m)) return 'اسکیلِ ' . $m[1];
    if (preg_match('#^Agents-Utility/(Methods|Procedures)/(.+?)\.md$#u', $d, $m)) return ($m[1] === 'Methods' ? 'متدِ ' : 'رویهٔ ') . $m[2];
    return $d;
}

/** Topic and taxonomy of every bundle: nightly index first, then the bundle's own analysis.json (host cache, a few
 *  fetches per tick so the first build stays short). */
function board_topics(array $keys, array $idx, array $plain, array $pool): array {
    $cache = board_read('topics.json', []);
    $budget = 25; $now = time();
    foreach ($keys as $k) {
        $i = $idx[$k] ?? null;
        if ($i && (($i['plain'] ?? '') !== '' || ($i['topic'] ?? '') !== '')) {
            $cache[$k] = ['plain' => $plain[$k] ?? ($i['plain'] ?? ''), 'topic' => (string) ($i['topic'] ?? ''), 'root' => $i['root'] ?? null, 'sub' => $i['sub'] ?? null, 'at' => $now];
            continue;
        }
        $c = $cache[$k] ?? null;
        if ($c && (($c['plain'] ?? '') !== '' || $now - (int) ($c['at'] ?? 0) < 86400)) continue;
        if ($budget-- <= 0) continue;
        $src = ($pool[$k]['source'] ?? '') === 'drive' ? ['drive', 'telegram'] : ['telegram', 'drive'];
        $a = null;
        foreach ($src as $sname) { $a = json_decode((string) gh_raw('Memory-Workflow/intake-pool/state/' . $sname . '/' . $k . '/analysis.json'), true); if (is_array($a)) break; }
        $cl = is_array($a) ? ($a['classification'] ?? []) : [];
        $tf = is_array($a) ? ($a['topic_and_flow'] ?? []) : [];
        $desc = trim((string) ($tf['paragraph_problem'] ?? '') . ' ' . (string) ($tf['paragraph_proposal'] ?? ''));
        $cache[$k] = ['plain' => (string) ($plain[$k] ?? ($cl['plain'] ?? '')), 'topic' => is_string($a['topic'] ?? null) ? $a['topic'] : '',
                      'desc' => $desc, 'root' => $cl['root'] ?? null, 'sub' => $cl['sub'] ?? null, 'at' => $now];
    }
    board_write('topics.json', $cache);
    return $cache;
}

/** Cover image of one bundle (protocol: Agent-Project-Backup/Memory-Software-Builder/Android/My-Control-Project/design/BUNDLE-COVER.md).
 *  The frame named in data/bundle-covers.json (picked by source/tools/bundle-cover.py); a bundle not listed there gets
 *  the middle frame of its first video. Cached here once, shrunk to 480 px wide when GD is present; a miss is
 *  remembered for a day so a bundle without frames never costs a GitHub call per card. Returns the cached file or null. */
function board_cover_file(string $k): ?string {
    if (!preg_match('/^[A-Za-z0-9_+-]{3,90}$/', $k)) return null;
    $dir = board_var('covers'); if (!is_dir($dir)) mkdir($dir, 0700, true);
    $f = $dir . '/' . md5($k) . '.jpg'; $miss = $f . '.none';
    if (is_file($f)) return $f;
    // ⛔ نامِ تازهٔ نشانِ «نبود» (۱۴۰۵-۰۸-۱۸): نشان‌های پیشین پیش از راهِ عکسیِ Drive ساخته شده بودند و یک روز کاورِ ۵۵ بستهٔ عکسی را می‌بستند.
    $miss = $f . '.none3';
    if (is_file($miss) && time() - filemtime($miss) < 3600) return null;   // یک ساعت، نه یک روز: نبودِ کاور زود بازآزموده شود
    $idx = board_read('covers.json', []);
    $path = is_array($idx[$k] ?? null) ? (string) ($idx[$k]['path'] ?? '') : '';
    if ($path === '') {
        foreach (['telegram', 'drive'] as $src) {
            [$c, $l] = gh('GET', '/repos/' . CTL_REPO . '/contents/Memory-Workflow/intake-pool/state/' . $src . '/' . rawurlencode($k) . '/frames?ref=' . CTL_BRANCH);
            if ($c !== 200 || !is_array($l)) continue;
            $names = array_values(array_filter(array_column($l, 'name'), fn($n) => str_ends_with($n, '.jpg'))); sort($names);
            if (!$names) continue;
            $first = preg_replace('/_f\d+\.jpg$/', '', $names[0]);
            $mine = array_values(array_filter($names, fn($n) => str_starts_with($n, $first . '_f')));
            $path = 'Memory-Workflow/intake-pool/state/' . $src . '/' . $k . '/frames/' . $mine[intdiv(count($mine), 2)];
            break;
        }
    }
    $raw = $path !== '' ? gh_raw($path) : null;
    // ⛔ بستهٔ عکسی قابی روی گیت ندارد و نباید داشته باشد (قفل: photo-bundle-stays-off-git)؛ پس کاورش اسلایدِ اول از Drive است
    //    (۱۴۰۵-۰۸-۱۸ · ردیف: photo-bundle-card-has-no-cover · قفل: photo-bundle-cover-comes-from-drive). فقط در کشِ همین هاست می‌ماند.
    if ($raw === null) $raw = board_photo_cover($k);
    if ($raw === null) { touch($miss); return null; }
    if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($raw))) {
        $w = imagesx($im); $h = imagesy($im);
        if ($w > 480) { $nh = (int) round($h * 480 / $w); $sm = imagecreatetruecolor(480, $nh); imagecopyresampled($sm, $im, 0, 0, 0, 0, 480, $nh, $w, $h); $im = $sm; }
        imagejpeg($im, $f . '.tmp', 78); rename($f . '.tmp', $f);
    } else { file_put_contents($f . '.tmp', $raw); rename($f . '.tmp', $f); }
    return $f;
}

/** Slide 1 of a photo bundle, read from Drive with the holding's single Drive account — the same account and files
 *  studio.hohedoc.ir uses (var/drive-default.json, var/drive-accounts/<ACC>.json, var/drive-web-client.json in the sibling
 *  domain folder of this host, outside every web root). No key is copied here; the access token is cached for 50 minutes. */
function board_photo_cover(string $k): ?string {
    $st = null;
    foreach (['telegram', 'drive'] as $src) {
        $raw = gh_raw('Memory-Workflow/intake-pool/state/' . $src . '/' . $k . '/status.json');
        if ($raw !== null) { $st = json_decode($raw, true); break; }
    }
    if (!is_array($st) || ($st['media_kind'] ?? '') !== 'photo') return null;
    // ⛔ کاورِ ماندگار اول (۱۴۰۵-۰۸-۱۹ · ردیف: photo-bundle-cover-dies-with-the-drive-purge · قفل: photo-bundle-cover-outlives-the-purge):
    //    تحویلِ شبانه پوشهٔ درایوِ بسته را همان شب پاک می‌کند؛ نسخهٔ intake-pool/covers/<key>.jpg می‌ماند (Sys.scripts/pool_cover_keep.py).
    $fr = [];
    $cid = (string) ($st['cover_drive_id'] ?? '');
    if ($cid !== '') $fr[''] = $cid;
    $up = $st['drive_files']['uploaded'] ?? [];
    foreach ((array) $up as $u) {
        $p = (string) ($u['rel_path'] ?? ''); $id = (string) ($u['drive_id'] ?? '');
        if ($id !== '' && str_starts_with($p, 'frames/') && preg_match('/\.(jpe?g|png|webp)$/i', $p)) $fr[$p] = $id;
    }
    if (!$fr) return null;
    ksort($fr);                       // '' (کاورِ ماندگار) پیش از هر frames/...
    $tok = board_drive_token();
    if ($tok === null) return null;
    foreach ([reset($fr), next($fr)] as $id) {   // کاورِ ماندگار، و اگر نبود اسلایدِ اولِ پوشهٔ بسته
        if (!$id) break;
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?alt=media');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'User-Agent: control-hohedoc']]);
        $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200 && is_string($raw) && strlen($raw) > 100) return $raw;
    }
    return null;
}

/** Booleans only (no path, no key): can this host read the studio's Drive files and mint a token? For op=ping&diag=cover. */
function board_photo_cover_diag(): array {
    $root = realpath(CTL_ROOT) ?: CTL_ROOT;
    $var = dirname($root) . '/studio.hohedoc.ir/var';
    $def = @json_decode((string) @file_get_contents($var . '/drive-default.json'), true);
    $acc = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($def['account'] ?? 'BACKUP'));
    return ['studio_var' => is_dir($var), 'default' => is_array($def), 'account' => is_file($var . '/drive-accounts/' . $acc . '.json'),
            'client' => is_file($var . '/drive-web-client.json'), 'token' => board_drive_token() !== null];
}

/** Access token of the holding's Drive account, refreshed from studio.hohedoc.ir's own files (same server account). */
function board_drive_token(): ?string {
    $cache = board_read('drive-token.json', []);
    if (is_array($cache) && ($cache['exp'] ?? 0) > time() + 60) return (string) $cache['token'];
    $root = realpath(CTL_ROOT) ?: CTL_ROOT;
    $var = dirname($root) . '/studio.hohedoc.ir/var';
    $def = @json_decode((string) @file_get_contents($var . '/drive-default.json'), true);
    $acc = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($def['account'] ?? 'BACKUP'));
    $a = @json_decode((string) @file_get_contents($var . '/drive-accounts/' . $acc . '.json'), true);
    $c = @json_decode((string) @file_get_contents($var . '/drive-web-client.json'), true);
    if (!is_array($a) || empty($a['refresh_token']) || !is_array($c) || empty($c['id']) || empty($c['secret'])) return null;
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query(['client_id' => $c['id'], 'client_secret' => $c['secret'],
            'refresh_token' => $a['refresh_token'], 'grant_type' => 'refresh_token'])]);
    $r = json_decode((string) curl_exec($ch), true); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || empty($r['access_token'])) return null;
    board_write('drive-token.json', ['token' => $r['access_token'], 'exp' => time() + 3000]);
    return (string) $r['access_token'];
}

/** Taxonomy roots {id: {title, subs: {id: title}}} — port of nightly_pool.load_taxonomy (no YAML library). */
function board_taxonomy(string $y): array {
    $roots = []; $cur = null; $in = false;
    foreach (preg_split('/\r?\n/', $y) as $line) {
        if (preg_match('/^roots:\s*$/', $line)) { $in = true; continue; }
        if ($in && preg_match('/^[A-Za-z_]/', $line)) break;
        if (!$in) continue;
        if (preg_match('/^\s{2}- id:\s*([\w-]+)/', $line, $m)) { $cur = $m[1]; $roots[$cur] = ['title' => '', 'subs' => []]; continue; }
        if ($cur && preg_match('/^\s{4}title:\s*(.+?)\s*$/', $line, $m)) { $roots[$cur]['title'] = $m[1]; continue; }
        if ($cur && preg_match('/^\s{6}- \{id:\s*([\w-]+),\s*title:\s*([^}]+)\}/', $line, $m)) $roots[$cur]['subs'][$m[1]] = trim($m[2]);
    }
    return $roots;
}

/** The «Routine Trainer List» queue: bundles waiting for step 3 (port of telegram_menu.pending_rows + build_pages
 *  grouping). Pending = pool status with an archive and not closed; root/sub/plain from the nightly index. */
function board_queue(array $pc, ?array $ni): array {
    $tax = board_taxonomy((string) gh_raw('Sys.registry/identity-taxonomy.yaml'));
    $plain = json_decode((string) gh_raw('Memory-Workflow/nightly-pool/plain.json'), true) ?: [];
    $idx = []; foreach ((array) ($ni['items'] ?? []) as $r) if (!empty($r['key'])) $idx[$r['key']] = $r;
    $rows = [];
    foreach (($pc['statuses'] ?? []) as $s) {
        if (($s['status'] ?? '') === 'بسته‌شده' || empty($s['archive'])) continue;
        $k = $s['key']; $i = $idx[$k] ?? []; $a = $s['archive'];
        $topic = $plain[$k] ?? ($i['plain'] ?? null) ?: ($a['topic'] ?? $i['topic'] ?? '—');
        $rows[] = ['key' => $k, 'root' => $i['root'] ?? null, 'sub' => $i['sub'] ?? null, 'plain' => $topic, 'issue' => (string) ($i['topic'] ?? ''),
                   'urgent' => !empty($s['urgent']), 'link' => $a['link'] ?? null, 'date' => $a['date'] ?? '', 'jalali' => $a['jalali'] ?? ''];
    }
    usort($rows, fn($x, $y) => [$y['urgent'], $x['date'], $x['key']] <=> [$x['urgent'], $y['date'], $y['key']]);
    $groups = [];
    foreach ($tax as $rid => $r) {
        $mine = array_values(array_filter($rows, fn($x) => $x['root'] === $rid));
        if (!$mine) continue;
        $subs = [];
        foreach ($r['subs'] as $sid => $st) { $xs = array_values(array_filter($mine, fn($x) => $x['sub'] === $sid)); if ($xs) $subs[] = ['id' => $sid, 'title' => $st, 'keys' => array_column($xs, 'key')]; }
        $rest = array_values(array_filter($mine, fn($x) => !isset($r['subs'][$x['sub']])));
        if ($rest) $subs[] = ['id' => 'other', 'title' => 'بی‌زیرشاخه', 'keys' => array_column($rest, 'key')];
        $groups[] = ['id' => $rid, 'title' => $r['title'], 'count' => count($mine), 'subs' => $subs];
    }
    $stray = array_values(array_filter($rows, fn($x) => !isset($tax[$x['root']])));
    if ($stray) $groups[] = ['id' => 'other', 'title' => 'بی‌دسته', 'count' => count($stray), 'subs' => [['id' => 'other', 'title' => 'بی‌زیرشاخه', 'keys' => array_column($stray, 'key')]]];
    usort($groups, fn($a, $b) => $b['count'] <=> $a['count']);
    $items = [];
    foreach ($rows as $x) {
        $t = trim(preg_replace('/\s+/', ' ', (string) $x['plain']), " .");
        $t = ($t === '' || $t === '—') ? '' : $t;                       // full text: the app shows up to five lines, never «…»
        $short = mb_strlen($t) > 60 ? rtrim(mb_substr($t, 0, 59)) : $t;   // the session name stays short
        $issue = trim(preg_replace('/\s+/', ' ', (string) ($x['issue'] ?? '')), " .");
        $items[$x['key']] = ['plain' => $t, 'title' => $issue, 'urgent' => $x['urgent'], 'link' => $x['link'], 'jalali' => $x['jalali'],
                             'prompt' => 'گامِ ۳ · ' . $x['key'] . ($short !== '' ? ' · ' . $short : '')];
    }
    return ['total' => count($rows), 'urgent' => count(array_filter($rows, fn($x) => $x['urgent'])), 'groups' => $groups, 'items' => $items];
}

function board_build(): array {
    $L = json_decode((string) gh_raw('Memory-Workflow/mapping-ledger/ledger.json'), true)['entries'] ?? null;
    $P = gh_raw('Memory-Workflow/upgrade-adoption/probes.yaml');
    $runs = gh_raw('Memory-Workflow/close-package/_runs.md');
    $pc = json_decode((string) gh_raw('Memory-Workflow/intake-pool/state/pool-contents.json'), true);
    $docs = gh_raw('docs/فهرست-ویدیوها.md');
    if (!is_array($L) || $P === null || $runs === null || !is_array($pc) || $docs === null) throw new RuntimeException('a source file is unreadable');
    $probes = board_probes($P);
    [$cf, $fbl] = gh('GET', '/repos/' . CTL_REPO . '/contents/Memory-Workflow/step3-feedback?ref=' . CTL_BRANCH);
    $fb = [];
    foreach (($cf === 200 ? $fbl : []) as $f) if (str_ends_with($f['name'], '.json')) $fb[substr($f['name'], 0, -5)] = 1;
    preg_match_all('/^\|\s*(\S+)\s*\|\s*\S+\s*\|\s*(\S+)\s*\|[^\n]*\|\s*success\s*\|/m', $runs, $rm, PREG_SET_ORDER);
    $closed = []; foreach ($rm as $r) $closed[$r[2]] ??= $r[1];
    $pool = []; foreach (($pc['statuses'] ?? []) as $s) $pool[$s['key']] = $s;
    preg_match_all('/^## (\S+) \(منبع/m', $docs, $dm); $docsClosed = array_flip($dm[1]);
    // content links: nightly index first, pool archive on top
    $links = [];
    $ni = json_decode((string) gh_raw('Memory-Workflow/nightly-pool/index.json'), true);
    foreach ((array) ($ni['items'] ?? $ni ?? []) as $r) if (is_array($r) && !empty($r['key'])) { $l = $r['archive_link'] ?? $r['link'] ?? $r['source_url'] ?? ''; if ($l) $links[$r['key']] = $l; }
    foreach ($pool as $k => $s) { $a = $s['archive'] ?? []; $l = $a['link'] ?? $s['source_url'] ?? ''; if ($l) $links[$k] = $l; }
    // report heads, cached by blob sha
    $heads = board_read('report-heads.json', []);
    [$cr, $rl] = gh('GET', '/repos/' . CTL_REPO . '/contents/Memory-Workflow/intake-pool/reports?ref=' . CTL_BRANCH);
    $seen = [];
    foreach (($cr === 200 ? $rl : []) as $f) {
        if (!str_ends_with($f['name'], '.md')) continue;
        $k = substr($f['name'], 0, -3); $seen[$k] = 1;
        if (($heads[$k]['sha'] ?? '') === $f['sha']) continue;
        $t = gh_raw('Memory-Workflow/intake-pool/reports/' . $f['name']);
        if ($t !== null) $heads[$k] = ['sha' => $f['sha']] + board_head($t);
    }
    foreach (array_keys($heads) as $k) if (!isset($seen[$k])) unset($heads[$k]);
    board_write('report-heads.json', $heads);
    $ledgerSess = board_ledger_sessions();
    // titles: the host copy (seeded once from the repo's step3-board/titles.json while it still exists)
    $cv = json_decode((string) gh_raw('Memory-Software-Builder/Android/My-Control-Project/data/bundle-covers.json'), true);
    if (is_array($cv)) board_write('covers.json', $cv);
    $titles = board_read('titles.json', []);
    $tax = board_taxonomy((string) gh_raw('Sys.registry/identity-taxonomy.yaml'));
    $plainMap = json_decode((string) gh_raw('Memory-Workflow/nightly-pool/plain.json'), true) ?: [];
    $nidx = []; foreach ((array) ($ni['items'] ?? []) as $r) if (is_array($r) && !empty($r['key'])) $nidx[$r['key']] = $r;
    if (!$titles) { $t = json_decode((string) gh_raw('Memory-Workflow/step3-board/titles.json'), true); if (is_array($t)) { unset($t['_note']); $titles = $t; board_write('titles.json', $t); } }

    $by = [];
    foreach ($L as $e) $by[$e['key']][] = $e;
    $topics = board_topics(array_values(array_unique(array_merge(array_keys($by), array_keys((array) board_read('extra.json', ['b8034' => 1]))))), $nidx, $plainMap, $pool);
    $about = function (string $k) use ($topics, $tax): array {
        $t = $topics[$k] ?? [];
        $r = $t['root'] ?? null; $sb = $t['sub'] ?? null;
        return ['topic' => (string) (($t['plain'] ?? '') ?: ($t['topic'] ?? '')), 'desc' => (string) ($t['desc'] ?? ''),
                'tax_root' => $r ? ($tax[$r]['title'] ?? $r) : null, 'tax_sub' => ($r && $sb) ? ($tax[$r]['subs'][$sb] ?? $sb) : null];
    };
    $s3runs = board_step3_runs();
    $out = [];
    foreach ($by as $k => $es) {
        $ent = count(array_filter($es, fn($e) => !empty($e['entered_repo'])));
        $hi = count(array_filter($es, fn($e) => ($e['score'] ?? 0) >= 6));
        // a probe belongs to the cycle it was written in: a bundle re-harvested and mapped again (urgent-reharvest-41
        // purged reports, feedback and ledger rows but not probes.yaml) must not inherit the old cycle's 3.6 (b6943, 1405-08-18)
        $cycle = min(array_map(fn($e) => board_jdigits((string) ($e['date'] ?? '')), $es) ?: ['']);
        $prE = array_values(array_filter($probes[$k] ?? [], fn($x) => $cycle === '' || $x['d'] === '' || $x['d'] >= $cycle));
        $pr = array_map(fn($x) => $x['r'], $prE);
        $ps = $pool[$k]['status'] ?? null;
        $outpool = $ps === null && !isset($closed[$k]) && !isset($docsClosed[$k]);
        $allpass = $pr && count(array_filter($pr, fn($x) => $x === 'pass')) === count($pr);
        $isclosed = isset($closed[$k]) || $ps === 'بسته‌شده' || isset($docsClosed[$k]) || ($outpool && $allpass);
        $flags = [];
        if ($isclosed) {
            $step = '4';
            $sub = (isset($closed[$k]) || $ps === 'بسته‌شده') ? 'بسته شد' : (isset($docsClosed[$k]) ? 'بسته شد (پیش از دفترِ اجراها)'
                 : (preg_match('/^(b\d+|1[\w-]{20,})/', $k) ? 'پاک‌شده از استخر بی اجرای بستن؛ آزمون‌ها قبول' : 'بیرون از استخر؛ آزمون‌ها قبول'));
            if (!isset($fb[$k]) && !$outpool) $flags[] = 'بی برداشتِ بازخوردِ گامِ ۳ بسته شد';
            if ($hi > 0 && $ent === 0) $flags[] = 'ردیفِ نمرهٔ ۶ به بالا وارد مخزن نشد';
        } elseif ($pr) {
            $step = '3.6';
            $sub = count($pr) . ' کاوشگر · ' . ($allpass ? 'آزمون پاس' : (in_array('fail', $pr, true) ? 'آزمون رد' : 'آزمون نشده'));
        } elseif ($ent > 0 || $hi > 0) {
            $step = 'up'; $sub = $ent > 0 ? 'ارتقا نوشته شد' : 'ارتقا در انتظار';
        } else {
            $step = '3'; $sub = 'بی ردیفِ ارزش‌دار';
        }
        // session: report head first, then the commit that first added the ledger row
        $h = $heads[$k] ?? [];
        $first = $ledgerSess[$k] ?? [];
        $session = $h['session_url'] ?? ($first[0]['s'] ?? null);
        $kind = 'session';
        if (!$isclosed && ($h['origin'] ?? '') === 'nightly-priority' && ($h['session'] ?? '') !== 'interactive') {
            // the night session wrote the analysis; the user's own session is the first later ledger commit from another session
            $mine = null;
            foreach ($first as $x) if ($x['s'] && $x['s'] !== ($h['session_url'] ?? '')) { $mine = $x['s']; break; }
            $since = ''; $uwork = 0;
            if (!$mine) {
                $mine = board_user_session($k, (string) ($h['session_url'] ?? ''));
                $us = board_read('user-sessions.json', [])[$k] ?? [];
                $since = $us['since'] ?? ''; $uwork = (int) ($us['work'] ?? 0);
            }
            if ($mine) {
                // the user's session took the bundle. Until it does real work itself (a ledger row OR a probe/close
                // commit beyond just opening), upgrades are the night session's work (lesson b7993/b8018, 1405-08-04)
                $session = $mine; $kind = 'opened';
                $moved = $since === '' || $uwork > 0 || count(array_filter($first, fn($x) => $x['at'] >= $since)) > 0;
                if (!$moved && $step !== '3') { $flags[] = 'ارتقا یا کاوشگر پیش از دیدنِ گزارش نوشته شد'; $step = '3'; }
                if ($step === '3') $sub = 'سشنت باز است؛ گزارش را ببین';
            }
            else { if ($step !== '3') $flags[] = 'ارتقا یا کاوشگر پیش از دیدنِ گزارش نوشته شد'; $kind = 'nightly'; $session = null; $step = '3'; $sub = 'تحلیلِ شبانه آماده؛ منتظرِ سشنِ تو'; }
        }
        // a step 3 run by the app button (report origin «routine», 1405-08-18 · b8239): the user has not read the report
        // yet, so a score ≥ 6 row is not «upgrade pending» — the bundle stays in step 3 until the step-3 feedback is taken
        if (!$isclosed && !$pr && ($h['origin'] ?? '') === 'routine' && !isset($fb[$k]) && $ent === 0) {
            $step = '3'; $sub = 'گزارش آماده؛ ببین و نظرت را بگو';
        }
        $rs = $s3runs[$k] ?? '';
        // ⛔ اجرای ناتمامی که پس از آن گزارشِ کامل نشسته کهنه است (۱۴۰۵-۰۸-۱۹ · b8877: بارِ اول ناتمام، بارِ دوم کامل؛ دیسپچر
        //    برای بارِ دوم فایلِ done ننوشت و کارت پرچمِ «ناموفق» گرفت)
        if ($rs === 'failed' && ($h['status'] ?? '') === 'step3-done') $rs = '';
        if (!$isclosed && in_array($rs, ['partial', 'failed'], true)) {
            $flags[] = 'اجرای گامِ ۳ «' . ($rs === 'partial' ? 'نیمه‌کاره' : 'ناموفق') . '» ثبت شد — پرامپتِ ترمیم: Memory-Workflow/routine-host/runs/repair-prompt.md';
            if ($step !== '3' && $ent === 0) { $step = '3'; $sub = 'گامِ ۳ کامل نشد؛ اول ترمیم'; }
        }
        if (!$isclosed && $ps === null && !$outpool) $flags[] = 'ردِ بستن پیدا نشد و در استخر هم نیست';
        $title = $titles[$k] ?? '';
        // the bundle's own problem line (same as the waiting list) before the top mapping point — b8239 turned into its
        // first upgrade's name the moment it left the queue (1405-08-18)
        if ($title === '') $title = trim(preg_replace('/\s+/', ' ', (string) ($topics[$k]['topic'] ?? '')), " .");
        if ($title === '') {
            usort($es, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $title = (string) ($es[0]['point'] ?? '');
            foreach ([':', '؛', ' — ', '،', '('] as $sep) $title = explode($sep, $title)[0];
            $title = mb_substr(trim($title), 0, 45);
        }
        $title = $title ?: $k;
        $closedAt = $closed[$k] ?? '';
        // گزارشِ گامِ ۳٫۶ (نتیجهٔ هر کاوشگر) و گامِ ۴ (چه شد هنگامِ بستن)
        $step36res = [];
        foreach (array_values($pr) as $pi => $pres) {
            $lbl = $pres === 'pass' ? 'پاس' : ($pres === 'fail' ? 'رد' : ($pres !== '' ? $pres : 'آزمون نشده'));
            $rt = (int) ($prE[$pi]['t'] ?? 0);
            $step36res[] = 'کاوشگرِ ' . ($pi + 1) . ': ' . $lbl . ($rt ? ' · ' . board_tehran_hm($rt) : '');
        }
        $step4res = [];
        if ($isclosed) { $step4res[] = $sub; foreach ($flags as $fl) $step4res[] = $fl; }
        $out[] = ['key' => $k, 'title' => $title, 'step' => $step, 'sub' => $sub, 'flags' => $flags,
                  'session' => $session, 'kind' => $kind, 'link' => $links[$k] ?? null,
                  'prompt' => $kind === 'nightly' ? 'گامِ ۳ · ' . $k . ' · ' . mb_substr($title, 0, 60) : null,
                  'closed_utc' => $closedAt, 'closed_j' => $closedAt ? ctl_jalali(strtotime($closedAt)) : '',
                  'step36_result' => $step36res, 'step4_result' => $step4res,
                  'date' => min(array_map(fn($e) => (string) ($e['date'] ?? ''), $es)), 'rows' => count($es)]
                + $about($k) + ['map' => array_map(fn($e) => ['node' => board_leaf((string) ($e['leaf'] ?? '')), 'dest' => board_dest((string) ($e['destination'] ?? '')),
                    'score' => (int) ($e['score'] ?? 0), 'consulted' => array_values(array_filter((array) ($e['consulted'] ?? []), 'is_string')),
                    'name' => board_pname((string) ($e['point'] ?? '')), 'point' => (string) ($e['point'] ?? ''),
                    'state' => (string) ($e['state'] ?? ''), 'entered' => !empty($e['entered_repo']), 'note' => (string) ($e['note'] ?? '')], $es)];
    }
    // bundles whose step-3 session left no trace in git (no ledger row, no report, no commit naming them) are
    // invisible to the five sources; extra.json (host only) puts them back in step 3 without a session so the app
    // button can run step 3 again (user decision 1405-08-12, first case: b8034).
    $seenKeys = array_flip(array_column($out, 'key'));
    foreach ((array) board_read('extra.json', ['b8034' => ['since' => '1405-08-12']]) as $k => $meta) {
        if (isset($seenKeys[$k]) || !preg_match('/^b[0-9]+$/', $k)) continue;
        $t = $titles[$k] ?? '';
        if ($t === '') foreach ((array) ($ni['items'] ?? []) as $r) if (($r['key'] ?? '') === $k) $t = mb_substr((string) ($r['topic'] ?? ''), 0, 45);
        $t = $t ?: $k;
        $out[] = ['key' => $k, 'title' => $t, 'step' => '3', 'sub' => 'سشنِ قبلی ردی در گیت‌هاب نگذاشت؛ دوباره اجرا کن',
                  'flags' => ['سشنِ گامِ ۳ باز شد ولی هیچ ردیفِ نگاشتی ننوشت'], 'session' => null, 'kind' => 'nightly',
                  'link' => $links[$k] ?? null, 'prompt' => 'گامِ ۳ · ' . $k . ' · ' . mb_substr($t, 0, 60),
                  'closed_utc' => '', 'closed_j' => '', 'date' => '', 'rows' => 0] + $about($k) + ['map' => []];
    }
    // a session the app button already made (routine host, fire-state.json) counts as the user's session even
    // before it writes anything to git — otherwise the bundle stays «بی‌سشن» while it is running (lesson b8034).
    $fire = @json_decode((string) @file_get_contents(ctl_var('fire-state.json')), true) ?: [];
    foreach ($out as &$b) {
        $f = $fire[$b['key']] ?? null;
        if ($b['kind'] === 'nightly' && is_array($f) && ($f['state'] ?? '') === 'fired' && !empty($f['session'])) {
            $b['kind'] = 'opened'; $b['session'] = (string) $f['session']; $b['prompt'] = null;
            $b['sub'] = 'سشن با دکمهٔ اپ ساخته شد؛ گزارش را ببین';
        }
    }
    unset($b);
    $queue = board_queue($pc, is_array($ni) ? $ni : null);
    // a queue bundle the app button already opened a session for leaves the waiting list and joins «گامِ ۳ به بعد»
    // at once (user ask 1405-08-18: «تا از این لیست حذف شود و به لیست بعد از گام سه منتقل شود»), before the
    // session writes its first ledger row; when the row lands, the ledger copy above replaces this one.
    $onBoard = array_flip(array_column($out, 'key'));
    foreach ((array) ($queue['items'] ?? []) as $k => $it) {
        $f = $fire[$k] ?? null;
        if (isset($onBoard[$k]) || !is_array($f) || ($f['state'] ?? '') !== 'fired' || empty($f['session'])) continue;
        // a session fired in a past cycle does not move a bundle pulled again since (re-harvest)
        $pulled = strtotime((string) ($pool[$k]['pulled_utc'] ?? '')) ?: 0;
        if ($pulled && (int) ($f['at'] ?? 0) < $pulled) continue;
        $out[] = ['key' => $k, 'title' => (string) (($it['title'] ?? '') ?: ($it['plain'] ?? '') ?: $k), 'step' => '3',
                  'sub' => 'سشن با دکمهٔ اپ ساخته شد؛ گزارش را ببین', 'flags' => [], 'session' => (string) $f['session'],
                  'kind' => 'opened', 'link' => $it['link'] ?? null, 'prompt' => null, 'closed_utc' => '', 'closed_j' => '',
                  'date' => gmdate('Y-m-d', (int) ($f['at'] ?? time())), 'rows' => 0] + $about((string) $k) + ['map' => []];
        unset($queue['items'][$k]);
        foreach ($queue['groups'] as &$g) {
            foreach ($g['subs'] as &$sb) $sb['keys'] = array_values(array_filter($sb['keys'], fn($x) => $x !== $k));
            unset($sb);
            $g['count'] = array_sum(array_map(fn($sb) => count($sb['keys']), $g['subs']));
        }
        unset($g);
        $queue['total'] = max(0, (int) $queue['total'] - 1);
        if (!empty($it['urgent'])) $queue['urgent'] = max(0, (int) $queue['urgent'] - 1);
    }
    // a step-3 session that committed its mapping on its own branch but never landed it on main leaves the bundle
    // looking unmapped (b8239, 1405-08-18). Name the branch on the card instead of «no mapping row yet».
    $want = [];
    foreach ($out as $b) if (in_array($b['kind'], ['opened', 'nightly'], true) && empty($b['map'])) $want[$b['key']] = true;
    $stranded = $want ? board_stranded($want) : [];
    foreach ($out as &$b) {
        if (!isset($stranded[$b['key']])) continue;
        $b['stranded_branch'] = $stranded[$b['key']];
        $b['sub'] = 'نگاشت روی برنچ مانده؛ به main نرسیده';
        array_unshift($b['flags'], 'کارِ گامِ ۳ روی برنچِ ' . $stranded[$b['key']] . ' مانده و به main نرسیده — تا نیاید، این‌جا «نگاشت ثبت نشده» دیده می‌شود');
    }
    unset($b);
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
    $count = ['3' => 0, 'up' => 0, '3.6' => 0, '4' => 0];
    foreach ($out as $b) $count[$b['step']]++;
    return ['at' => time(), 'population' => count($out), 'count' => $count, 'queue' => $queue,
            'linked' => count(array_filter($out, fn($b) => $b['session'] || $b['kind'] === 'nightly')), 'bundles' => $out];
}

/** key => status of the latest routine-host run of step 3 (Memory-Workflow/routine-host/runs/step3-<key>-<ts>.*.json),
 *  cached by blob sha. «partial» or «failed» means the session stopped short (b8239: coverage stamp missing). */
function board_step3_runs(): array {
    [$c, $ls] = gh('GET', '/repos/' . CTL_REPO . '/contents/Memory-Workflow/routine-host/runs?ref=' . CTL_BRANCH);
    if ($c !== 200 || !is_array($ls)) return [];
    $cache = board_read('step3-runs.json', []);
    $latest = [];
    foreach ($ls as $f) {
        if (!preg_match('/^step3-(b[0-9]{1,8})-([0-9]{12})\.(done|failed)\.json$/', (string) ($f['name'] ?? ''), $m)) continue;
        if (isset($latest[$m[1]]) && $latest[$m[1]]['ts'] >= $m[2]) continue;
        $latest[$m[1]] = ['ts' => $m[2], 'name' => $f['name'], 'sha' => $f['sha'], 'kind' => $m[3]];
    }
    $out = []; $next = [];
    foreach ($latest as $k => $x) {
        if (($cache[$x['name']]['sha'] ?? '') === $x['sha']) $st = $cache[$x['name']]['status'];
        else { $j = json_decode((string) gh_raw('Memory-Workflow/routine-host/runs/' . $x['name']), true); $st = (string) ($j['status'] ?? ($x['kind'] === 'failed' ? 'failed' : '')); }
        $next[$x['name']] = ['sha' => $x['sha'], 'status' => $st];
        $out[$k] = $x['kind'] === 'failed' ? 'failed' : $st;
    }
    board_write('step3-runs.json', $next);
    return $out;
}

/** key => branch: unmerged branches of the private repo whose commits say «گامِ ۳ برای <key>».
 *  Compare results are cached by branch head sha, so a quiet tick costs one branch-list call. */
const BOARD_STRANDED_COMPARES_PER_TICK = 200;   // first scan covers every branch in one tick (25 left b6943 unflagged for 5 ticks)
function board_stranded(array $want): array {
    $cache = board_read('stranded-cache.json', []);
    $heads = [];
    for ($page = 1; $page <= 5; $page++) {
        [$c, $br] = gh('GET', '/repos/' . CTL_REPO . '/branches?per_page=100&page=' . $page);
        if ($c !== 200 || !is_array($br)) break;
        foreach ($br as $x) if (($x['name'] ?? '') !== CTL_BRANCH) $heads[(string) $x['name']] = (string) ($x['commit']['sha'] ?? '');
        if (count($br) < 100) break;
    }
    if (!$heads) return [];
    $budget = BOARD_STRANDED_COMPARES_PER_TICK;
    $next = [];
    foreach ($heads as $name => $sha) {
        if (isset($cache[$name]) && $cache[$name]['sha'] === $sha) { $next[$name] = $cache[$name]; continue; }
        if ($budget-- <= 0) continue;
        [$c, $cmp] = gh('GET', '/repos/' . CTL_REPO . '/compare/' . CTL_BRANCH . '...' . rawurlencode_path($name));
        if ($c !== 200) continue;
        $keys = [];
        if ((int) ($cmp['ahead_by'] ?? 0) > 0) foreach ((array) ($cmp['commits'] ?? []) as $k)
            if (preg_match_all('/گامِ ۳ برای (b[0-9]{1,8})/u', (string) ($k['commit']['message'] ?? ''), $m)) foreach ($m[1] as $x) $keys[$x] = true;
        $next[$name] = ['sha' => $sha, 'keys' => array_keys($keys)];
    }
    board_write('stranded-cache.json', $next);
    $out = [];
    foreach ($next as $name => $x) foreach ($x['keys'] as $k) if (isset($want[$k])) $out[$k] = $name;
    return $out;
}

/** From tick.php (cron). Never stops the workflow status tick. */
/** Cron only: the web PHP may be fenced to its own domain folder, the cron is not; so photo covers are fetched here
 *  (≤12 per tick) into the same cache op=cover serves. Its yes/no diagnosis is kept for op=ping&diag=cover. */
function board_prefetch_photo_covers(array $b): void {
    $d = board_photo_cover_diag();
    board_write('photo-cover-diag.json', $d + ['at' => time()]);
    if (!$d['token']) return;
    $keys = [];
    array_walk_recursive($b, function ($v, $k) use (&$keys) { if ($k === 'key' && is_string($v) && preg_match('/^b\d+$/', $v)) $keys[$v] = true; });
    $dir = board_var('covers'); $n = 0;
    foreach (array_keys($keys) as $k) {
        if (is_file($dir . '/' . md5($k) . '.jpg')) continue;
        @unlink($dir . '/' . md5($k) . '.jpg.none3');
        board_cover_file($k);
        if (++$n >= 12) break;
    }
}

function board_tick(): void {
    $lock = fopen(board_var('board.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    try {
        $b = board_build();
        if ($b['population'] === 0 || array_sum($b['count']) !== $b['population']) throw new RuntimeException('columns do not add up to the population');
        board_write('board.json', $b);
        board_write('board-state.json', ['ok' => true, 'at' => time()]);
        board_prefetch_photo_covers($b);
    } catch (Throwable $e) {
        board_write('board-state.json', ['ok' => false, 'at' => time(), 'error' => substr($e->getMessage(), 0, 200)]);
        ctl_log('board ' . substr($e->getMessage(), 0, 200));
    }
    flock($lock, LOCK_UN);
}
