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
    $titles = board_read('titles.json', []);
    if (!$titles) { $t = json_decode((string) gh_raw('Memory-Workflow/step3-board/titles.json'), true); if (is_array($t)) { unset($t['_note']); $titles = $t; board_write('titles.json', $t); } }

    $by = [];
    foreach ($L as $e) $by[$e['key']][] = $e;
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
                  'date' => min(array_map(fn($e) => (string) ($e['date'] ?? ''), $es)), 'rows' => count($es)];
    }
    usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
    $count = ['3' => 0, 'up' => 0, '3.6' => 0, '4' => 0];
    foreach ($out as $b) $count[$b['step']]++;
    return ['at' => time(), 'population' => count($by), 'count' => $count,
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
