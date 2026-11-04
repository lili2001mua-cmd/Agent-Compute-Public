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

/** probes.yaml is a flat list; only bundle and test_result are needed. */
function board_probes(string $yaml): array {
    $out = [];
    foreach (preg_split('/^  - /m', $yaml) as $i => $blk) {
        if ($i === 0) continue;
        preg_match('/^\s*bundle:\s*"?([^"\n]+)"?/m', $blk, $b);
        preg_match('/^\s*test_result:\s*"?([^"\n]*)"?/m', $blk, $t);
        if (!empty($b[1])) $out[trim($b[1])][] = trim($t[1] ?? '');
    }
    return $out;
}

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
    if ($c && time() - $c['at'] < 1800) return $c['s'];
    [$code, $j] = gh('GET', '/search/commits?q=' . rawurlencode('repo:' . CTL_REPO . ' ' . $key) . '&sort=committer-date&order=asc&per_page=50');
    if ($code !== 200) return $c['s'] ?? null;
    $s = null;
    foreach (($j['items'] ?? []) as $it) {
        if (preg_match('#(https://claude\.ai/code/session_\w+)#', (string) ($it['commit']['message'] ?? ''), $m) && $m[1] !== $night) {
            $s = $m[1]; $since = (string) ($it['commit']['committer']['date'] ?? ''); break;
        }
    }
    $cache[$key] = ['s' => $s, 'since' => $since ?? '', 'at' => time()];
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
        $cache[$k] = ['plain' => (string) ($plain[$k] ?? ($cl['plain'] ?? '')), 'topic' => is_string($a['topic'] ?? null) ? $a['topic'] : '',
                      'root' => $cl['root'] ?? null, 'sub' => $cl['sub'] ?? null, 'at' => $now];
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
    if (is_file($miss) && time() - filemtime($miss) < 86400) return null;
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
    if ($raw === null) { touch($miss); return null; }
    if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($raw))) {
        $w = imagesx($im); $h = imagesy($im);
        if ($w > 480) { $nh = (int) round($h * 480 / $w); $sm = imagecreatetruecolor(480, $nh); imagecopyresampled($sm, $im, 0, 0, 0, 0, 480, $nh, $w, $h); $im = $sm; }
        imagejpeg($im, $f . '.tmp', 78); rename($f . '.tmp', $f);
    } else { file_put_contents($f . '.tmp', $raw); rename($f . '.tmp', $f); }
    return $f;
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
        return ['topic' => (string) (($t['plain'] ?? '') ?: ($t['topic'] ?? '')),
                'tax_root' => $r ? ($tax[$r]['title'] ?? $r) : null, 'tax_sub' => ($r && $sb) ? ($tax[$r]['subs'][$sb] ?? $sb) : null];
    };
    $out = [];
    foreach ($by as $k => $es) {
        $ent = count(array_filter($es, fn($e) => !empty($e['entered_repo'])));
        $hi = count(array_filter($es, fn($e) => ($e['score'] ?? 0) >= 6));
        $pr = $probes[$k] ?? [];
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
            $since = '';
            if (!$mine) { $mine = board_user_session($k, (string) ($h['session_url'] ?? '')); $since = board_read('user-sessions.json', [])[$k]['since'] ?? ''; }
            if ($mine) {
                // the user's session took the bundle. Until it writes a ledger row itself, upgrades and probes are the
                // night session's work: the bundle stays in step 3 with a flag (lesson b7993/b8018, 1405-08-04)
                $session = $mine; $kind = 'opened';
                $moved = $since === '' || count(array_filter($first, fn($x) => $x['at'] >= $since)) > 0;
                if (!$moved && $step !== '3') { $flags[] = 'ارتقا یا کاوشگر پیش از دیدنِ گزارش نوشته شد'; $step = '3'; }
                if ($step === '3') $sub = 'سشنت باز است؛ گزارش را ببین';
            }
            else { if ($step !== '3') $flags[] = 'ارتقا یا کاوشگر پیش از دیدنِ گزارش نوشته شد'; $kind = 'nightly'; $session = null; $step = '3'; $sub = 'تحلیلِ شبانه آماده؛ منتظرِ سشنِ تو'; }
        }
        if (!$isclosed && $ps === null && !$outpool) $flags[] = 'ردِ بستن پیدا نشد و در استخر هم نیست';
        $title = $titles[$k] ?? '';
        if ($title === '') {
            usort($es, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $title = (string) ($es[0]['point'] ?? '');
            foreach ([':', '؛', ' — ', '،', '('] as $sep) $title = explode($sep, $title)[0];
            $title = mb_substr(trim($title), 0, 45);
        }
        $title = $title ?: $k;
        $closedAt = $closed[$k] ?? '';
        $out[] = ['key' => $k, 'title' => $title, 'step' => $step, 'sub' => $sub, 'flags' => $flags,
                  'session' => $session, 'kind' => $kind, 'link' => $links[$k] ?? null,
                  'prompt' => $kind === 'nightly' ? 'گامِ ۳ · ' . $k . ' · ' . mb_substr($title, 0, 60) : null,
                  'closed_utc' => $closedAt, 'closed_j' => $closedAt ? ctl_jalali(strtotime($closedAt)) : '',
                  'date' => min(array_map(fn($e) => (string) ($e['date'] ?? ''), $es)), 'rows' => count($es)]
                + $about($k) + ['map' => array_map(fn($e) => ['node' => board_leaf((string) ($e['leaf'] ?? '')), 'dest' => board_dest((string) ($e['destination'] ?? '')),
                    'score' => (int) ($e['score'] ?? 0), 'consulted' => array_values(array_filter((array) ($e['consulted'] ?? []), 'is_string'))], $es)];
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
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
    $count = ['3' => 0, 'up' => 0, '3.6' => 0, '4' => 0];
    foreach ($out as $b) $count[$b['step']]++;
    $queue = board_queue($pc, is_array($ni) ? $ni : null);
    return ['at' => time(), 'population' => count($out), 'count' => $count, 'queue' => $queue,
            'linked' => count(array_filter($out, fn($b) => $b['session'] || $b['kind'] === 'nightly')), 'bundles' => $out];
}

/** From tick.php (cron). Never stops the workflow status tick. */
function board_tick(): void {
    $lock = fopen(board_var('board.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    try {
        $b = board_build();
        if ($b['population'] === 0 || array_sum($b['count']) !== $b['population']) throw new RuntimeException('columns do not add up to the population');
        board_write('board.json', $b);
        board_write('board-state.json', ['ok' => true, 'at' => time()]);
    } catch (Throwable $e) {
        board_write('board-state.json', ['ok' => false, 'at' => time(), 'error' => substr($e->getMessage(), 0, 200)]);
        ctl_log('board ' . substr($e->getMessage(), 0, 200));
    }
    flock($lock, LOCK_UN);
}
