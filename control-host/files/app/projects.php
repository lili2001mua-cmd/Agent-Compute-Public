<?php
// Projects section of the My-Control-Project app («پروژه‌ها»), computed on this host — the app shows it live,
// no new app version is needed when an orchestra changes (user decision 1405-08-18: «پروژه‌ها باید زنده به‌روز
// شوند … وقتی آن‌جا به‌روز می‌شود، نرم‌افزار هم به‌روز شود»).
// Source of truth stays in the private repo: every Memory-Workflow/idea-orchestrations/<slug>/IDEA-ORCH.md and the
// category rules in Memory-Software-Builder/Android/My-Control-Project/orch-board/orch-categories.json .
// The parser is a port of Agent-Project-Backup/Sys.scripts/orch-board-build.py (header, sections, table, fallback,
// intro) — keep the two in step; parity is checked by running projects_from_local() against the Python output.
// Output: var/projects/projects.json (read by the app through api.php?op=projects).
require_once __DIR__ . '/lib.php';

const PROJ_DIR = 'projects';
const PROJ_ORCH = 'Memory-Workflow/idea-orchestrations';
const PROJ_CATS = 'Memory-Software-Builder/Android/My-Control-Project/orch-board/orch-categories.json';
const PROJ_COMMITS_PER_TICK = 30;          // «last change» of each doc: first run walks it in slices, later only changed docs
const PROJ_BULK_MARK = 'یکدست‌سازیِ تگِ وضعیت';   // bulk tag commits are not real work (same rule as the Python builder)

const PROJ_ORCH_TAGS = ['draft', 'approved', 'running', 'paused', 'done', 'closed'];
const PROJ_SEC_TAGS = ['open', 'running', 'review', 'blocked', 'done', 'dropped'];
const PROJ_SKIP_H2 = ['خلاصه', 'نیاز', 'یافته', 'تاریخچه', 'جملهٔ خام', 'پرسش', 'پاسخِ کاربر', 'پنل', 'بخش‌های اجرا', 'قاعده', 'درس', 'مرز',
    'خروجی', 'از کجا ادامه', 'ارکسترهای مرتبط', 'بازخوانی', 'فهرستِ نیاز', 'تصمیم‌های باز'];
const PROJ_DONE_HINT = ['تأییدشده', 'تأیید شد', '✅'];
const PROJ_STEP_H2 = ['روند', 'گام‌ها', 'مراحل', 'فازها', 'چک‌لیستِ اجرا', 'نقشهٔ اجرا', 'برنامهٔ اجرا'];
const PROJ_INTRO_H2 = ['خلاصهٔ ایده', 'خلاصه', 'تعریفِ اولیه', 'چرا', 'نیازهای شنیده', 'نیازها', 'تشخیص', 'هدف', 'جملهٔ خام', 'خروجی'];
const PROJ_RANK = ['running' => 0, 'draft' => 1, 'done' => 2, 'closed' => 3];

// ---------- parser (port of orch-board-build.py) ----------

function proj_strip(string $s): string { return preg_replace('/^\s+|\s+$/u', '', $s); }
function proj_clean(?string $s): string {
    $s = proj_strip((string) $s);
    $s = preg_replace('/^[\x{200F}\x{200E}\x{2066}\x{2067}\x{2068}\x{2069}]+|[\x{200F}\x{200E}\x{2066}\x{2067}\x{2068}\x{2069}]+$/u', '', $s);
    return proj_strip($s);
}
function proj_tag_of(?string $cell, array $allowed): ?string {
    preg_match_all('/[A-Za-z]+/', (string) $cell, $m);
    foreach ($m[0] as $w) if (in_array(strtolower($w), $allowed, true)) return strtolower($w);
    return null;
}
function proj_plain(?string $s): string {
    $s = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/u', '$1', (string) $s);
    return proj_clean(str_replace(['**', '`'], '', $s));
}
function proj_only(string $c, string $chars): bool {   // Python: set(c) <= set(chars)
    return preg_match('/^[' . preg_quote($chars, '/') . ']*$/u', $c) === 1;
}
function proj_trim_chars(string $s, string $chars): string {   // Python: s.strip(chars)
    $q = preg_quote($chars, '/');
    return preg_replace('/^[' . $q . ']+|[' . $q . ']+$/u', '', $s);
}
function proj_starts(string $s, array $keys): bool { foreach ($keys as $k) if (str_starts_with($s, $k)) return true; return false; }
function proj_lstrip_mark(string $t): string { return preg_replace('/^[⛔ ]+/u', '', $t); }

function proj_header(string $txt): array {
    $head = explode("\n## ", $txt, 2)[0];
    preg_match_all('/^\|\s*([^|\n]+?)\s*\|\s*([^|\n]*?)\s*\|\s*$/mu', $head, $m, PREG_SET_ORDER);
    $rows = [];
    foreach ($m as $r) {
        $k = proj_clean($r[1]);
        if ($k !== '' && !proj_only($k, '-: ') && !array_key_exists($k, $rows)) $rows[$k] = proj_clean($r[2]);
    }
    return $rows;
}

function proj_sections(string $txt): array {
    preg_match_all('/^###\s+(.+)$/mu', $txt, $hs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $out = [];
    $n = count($hs);
    for ($i = 0; $i < $n; $i++) {
        $start = $hs[$i][0][1] + strlen($hs[$i][0][0]);
        $end = $i + 1 < $n ? $hs[$i + 1][0][1] : strlen($txt);
        $nxt = strpos($txt, "\n## ", $start);
        if ($nxt !== false && $nxt < $end) $end = $nxt;
        $block = substr($txt, $start, $end - $start);
        preg_match_all('/^\s*-\s*\*\*([^*]+?):\*\*\s*(.*)$/mu', $block, $fm, PREG_SET_ORDER);
        $f = [];
        foreach ($fm as $x) { $k = proj_clean($x[1]); if (!array_key_exists($k, $f)) $f[$k] = proj_clean($x[2]); }
        if (!array_key_exists('وضعیت', $f)) continue;
        $title = preg_replace('/^بخشِ\s*/u', '', proj_plain($hs[$i][1][0]));
        preg_match_all('/`([^`]+)`/u', $f['مجری'] ?? '', $ex);
        $out[] = ['title' => $title, 'status' => proj_tag_of($f['وضعیت'], PROJ_SEC_TAGS) ?? 'unknown',
                  'executor' => $ex[1][0] ?? proj_plain($f['مجری'] ?? ''), 'note' => proj_plain($f['یادداشتِ وضعیت'] ?? '')];
    }
    return $out;
}

function proj_cell_status(string $cell): string {
    $t = proj_tag_of($cell, PROJ_SEC_TAGS);
    if ($t) return $t;
    $c = proj_plain($cell);
    if (str_contains($c, '✅') || str_starts_with($c, 'تمام')) return 'done';
    if (str_contains($c, 'در کار') || str_contains($c, 'در جریان')) return 'running';
    if (str_contains($c, 'منتظرِ') && (str_contains($c, 'کاربر') || str_contains($c, 'تأیید') || str_contains($c, 'تصمیم') || str_contains($c, '«بله»'))) return 'review';
    if (str_starts_with($c, '⏳') || str_starts_with($c, 'باز')) return 'open';
    return 'unknown';
}

function proj_table_sections(string $txt): array {
    $best = [];
    preg_match_all('/(?:^\|[^\n]*\|[ \t]*\n)+/mu', $txt, $tm);
    foreach ($tm[0] as $tbl) {
        $rows = [];
        foreach (explode("\n", proj_strip($tbl)) as $r) $rows[] = array_map('proj_clean', explode('|', proj_trim_chars(proj_strip($r), '|')));
        if (count($rows) < 3) continue;
        $head = $rows[0]; $si = $ti = $ei = null;
        foreach ($head as $i => $h) {
            if ($si === null && (str_contains($h, 'وضعیت') || str_contains($h, 'دروازهٔ خروج'))) $si = $i;
            if ($ti === null && proj_starts($h, ['بخش', 'مرحله', 'گام'])) $ti = $i;
            if ($ei === null && (str_contains($h, 'مالک') || str_contains($h, 'مجری'))) $ei = $i;
        }
        if ($si === null || $ti === null) continue;
        $out = [];
        foreach (array_slice($rows, 2) as $r) {
            if (count($r) <= max($si, $ti)) continue;
            $ex = [];
            if ($ei !== null && $ei < count($r)) preg_match_all('/`([^`]+)`/u', $r[$ei], $exm) && $ex = $exm[1];
            $out[] = ['title' => proj_plain($r[$ti]), 'status' => proj_cell_status($r[$si]), 'executor' => $ex[0] ?? '', 'note' => proj_plain($r[$si])];
        }
        if (count($out) > count($best)) $best = $out;
    }
    return $best;
}

function proj_excerpt(string $body, int $n = 320): string {
    $body = preg_replace('/```.*?```/su', ' ', $body);
    $keep = [];
    foreach (preg_split('/\r\n|\r|\n/u', $body) as $l) {   // Python splitlines()
        $s = proj_strip($l);
        if (str_starts_with($s, '|')) {
            $cells = array_map('proj_strip', explode('|', proj_trim_chars($s, '|')));
            $all = true; foreach ($cells as $c) if (!proj_only($c, '-: ')) { $all = false; break; }
            if (!$all) $keep[] = implode(' · ', array_values(array_filter($cells, fn($c) => $c !== ''))) . '؛';
        } elseif (!str_starts_with($s, '<!--') && !str_starts_with($s, '>')) {
            $keep[] = $l;
        }
    }
    $s = proj_trim_chars(preg_replace('/\s+/u', ' ', proj_plain(implode("\n", $keep))), ' -');
    if (mb_strlen($s) > $n) {
        $cut = mb_substr($s, 0, $n);
        $p = mb_strrpos($cut, ' ');
        return ($p === false ? $cut : mb_substr($cut, 0, $p)) . ' …';
    }
    return $s;
}

function proj_h2_bodies(string $txt): array {
    preg_match_all('/^##\s+(.+)$/mu', $txt, $hs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $out = []; $n = count($hs);
    for ($i = 0; $i < $n; $i++) {
        $start = $hs[$i][0][1] + strlen($hs[$i][0][0]);
        $end = $i + 1 < $n ? $hs[$i + 1][0][1] : strlen($txt);
        $out[] = [proj_plain($hs[$i][1][0]), substr($txt, $start, $end - $start)];
    }
    return $out;
}

function proj_phases_fallback(string $txt, string $orch_status): array {
    $derived = ['done' => 'done', 'closed' => 'done', 'draft' => 'open', 'approved' => 'open'][$orch_status] ?? '';
    $bodies = proj_h2_bodies($txt);
    $out = [];
    foreach ($bodies as [$t, $body]) {
        if (proj_starts(proj_lstrip_mark($t), PROJ_STEP_H2)) {
            preg_match_all('/^\s*([۰-۹0-9]+)[.)]\s+(.+)$/mu', $body, $it, PREG_SET_ORDER);
            if (count($it) >= 2) {
                foreach ($it as $x) $out[] = ['title' => $x[1] . ' — ' . rtrim(proj_plain($x[2]), '.'), 'note' => '', 'hint' => false];
                break;
            }
        }
    }
    if (!$out) {
        foreach ($bodies as [$t, $body]) {
            if (proj_starts(proj_lstrip_mark($t), PROJ_SKIP_H2)) continue;
            $hint = false; foreach (PROJ_DONE_HINT as $h) if (str_contains($t, $h)) $hint = true;
            $out[] = ['title' => $t, 'note' => proj_excerpt($body), 'hint' => $hint];
        }
    }
    $res = [];
    foreach ($out as $s) $res[] = ['title' => $s['title'], 'status' => $s['hint'] ? 'done' : ($derived ?: 'unknown'), 'executor' => '', 'note' => $s['note']];
    return $res;
}

function proj_intro(string $txt): string {
    $bodies = proj_h2_bodies($txt);
    foreach (PROJ_INTRO_H2 as $key) foreach ($bodies as [$t, $b]) {
        if (str_contains($t, $key)) {
            $e = proj_excerpt(preg_replace('/^\s*(>\s?|#+\s*)/mu', '', $b), 420);
            if (mb_strlen($e) > 20) return $e;
        }
    }
    return $bodies ? proj_excerpt($bodies[0][1], 420) : '';
}

/** Trainer docs stay off the board — their control is Telegram (port of Sys.scripts/lib/trainer_exempt.py,
 *  lock trainer-project-control-is-telegram): owner skill-trainer, a trainer project, or a trainer parent. */
const PROJ_TRAINER_PROJECTS = ['pool-button', 'nightly-pool-harvest'];
function proj_exempt(string $slug, string $txt): bool {
    preg_match_all('/^\|\s*([^|]+?)\s*\|\s*([^|]*?)\s*\|\s*$/mu', explode("\n## ", $txt, 2)[0], $m, PREG_SET_ORDER);
    $h = [];
    foreach ($m as $r) { $k = proj_strip($r[1]); if (!array_key_exists($k, $h)) $h[$k] = proj_strip(str_replace('`', '', $r[2])); }
    foreach (['مالکِ چرخه', 'مالکِ منطقی'] as $k) if (str_contains($h[$k] ?? '', 'skill-trainer')) return true;
    return in_array($slug, PROJ_TRAINER_PROJECTS, true) || in_array($h['پروژهٔ مادر'] ?? '', PROJ_TRAINER_PROJECTS, true);
}

/** One IDEA-ORCH.md ⇒ the fields the app shows (same shape as source/tools/build-snapshot.py orch_groups). */
function proj_doc(string $slug, string $txt): array {
    $h = proj_header($txt);
    $title = preg_match('/^#\s+(.+)$/mu', $txt, $t) ? proj_plain($t[1]) : $slug;
    $title = proj_strip(preg_replace('/^IDEA-ORCH\s*[—\-:]\s*/u', '', $title));
    $st_raw = $h['وضعیتِ کل'] ?? ($h['وضعیت کل'] ?? '');
    $tag = proj_tag_of($st_raw, PROJ_ORCH_TAGS);
    $secs = proj_sections($txt);
    if (!$secs) $secs = proj_table_sections($txt);
    if (!$secs) $secs = proj_phases_fallback($txt, $tag ?? '');
    $steps = [];
    foreach ($secs as $s) $steps[] = ['title' => $s['title'], 'status' => $s['status'], 'executor' => $s['executor'],
                                      'note' => $s['note'] === $s['status'] ? '' : $s['note']];
    return ['slug' => $slug, 'title' => $title, 'status' => $tag ?? ($st_raw !== '' ? 'unknown' : 'missing'),
            'status_note' => proj_plain($h['یادداشتِ وضعیت'] ?? ''), 'date' => proj_plain($h['تاریخ'] ?? ''),
            'depth' => proj_plain($h['پلهٔ عمق'] ?? ''), 'parent' => proj_plain($h['پروژهٔ مادر'] ?? ''),
            'intro' => proj_intro($txt), 'steps' => $steps, 'exempt' => proj_exempt($slug, $txt)];
}

/** Days since the doc's last real commit, by Tehran calendar day (−1 when unknown). */
function proj_days(?int $ts, ?int $now = null): int {
    if (!$ts) return -1;
    $d = fn(int $x) => (int) floor(($x + 12600) / 86400);
    return max(0, $d($now ?? time()) - $d($ts));
}

/** docs: slug => [doc, ts] · cats: decoded orch-categories.json ⇒ groups for the app. */
function proj_groups(array $docs, array $cats): array {
    $rules = $cats['groups'] ?? [];
    $groups = []; foreach ($rules as $r) $groups[] = ['title' => $r['title'], 'orchs' => []];
    ksort($docs, SORT_STRING);
    foreach ($docs as $slug => $x) {
        if (!empty($x['doc']['exempt'])) continue;
        unset($x['doc']['exempt']);
        foreach ($rules as $i => $r) {
            if (preg_match('/' . str_replace('/', '\/', $r['pattern']) . '/u', (string) $slug)) {
                $groups[$i]['orchs'][] = $x['doc'] + ['changed' => proj_days($x['ts'] ?? null)];
                break;
            }
        }
    }
    foreach ($groups as &$g) usort($g['orchs'], fn($a, $b) => [PROJ_RANK[$a['status']] ?? 4, $a['title']] <=> [PROJ_RANK[$b['status']] ?? 4, $b['title']]);
    unset($g);
    return array_values(array_filter($groups, fn($g) => $g['orchs']));
}

// ---------- host tick ----------

function proj_var(string $name): string { $d = ctl_var(PROJ_DIR); if (!is_dir($d)) mkdir($d, 0700, true); return $d . '/' . $name; }
function proj_read(string $n, $def) { $x = @json_decode((string) @file_get_contents(proj_var($n)), true); return is_array($x) ? $x : $def; }
function proj_write(string $n, $data): void {
    $f = proj_var($n);
    file_put_contents($f . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($f . '.tmp', $f);
}
function proj_blob(string $sha): ?string {
    [$c, $j] = gh('GET', '/repos/' . CTL_REPO . '/git/blobs/' . $sha);
    return ($c === 200 && isset($j['content'])) ? base64_decode($j['content']) : null;
}

/** Every cron tick: re-parse only docs whose blob changed; write var/projects/projects.json. */
function projects_tick(): void {
    $lock = fopen(proj_var('tick.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    $st = ['at' => time(), 'ok' => false];
    try {
        [$c, $dir] = gh('GET', '/repos/' . CTL_REPO . '/contents/' . rawurlencode_path(dirname(PROJ_ORCH)) . '?ref=' . CTL_BRANCH);
        $tsha = null;
        foreach ((array) $dir as $e) if (($e['name'] ?? '') === basename(PROJ_ORCH)) $tsha = $e['sha'] ?? null;
        if ($c !== 200 || !$tsha) { $st['error'] = 'github ' . $c . ' (orchestra folder)'; return; }
        [$c, $tree] = gh('GET', '/repos/' . CTL_REPO . '/git/trees/' . $tsha . '?recursive=1');
        if ($c !== 200) { $st['error'] = 'github ' . $c . ' (tree)'; return; }
        $cache = proj_read('docs.json', []);
        $seen = []; $parsed = 0; $failed = 0;
        foreach (($tree['tree'] ?? []) as $e) {
            if (($e['type'] ?? '') !== 'blob' || !preg_match('#^([^/]+)/IDEA-ORCH\.md$#', (string) $e['path'], $m)) continue;
            $slug = $m[1]; $seen[$slug] = true;
            if (($cache[$slug]['sha'] ?? '') === $e['sha']) continue;
            $txt = proj_blob($e['sha']);
            if ($txt === null) { $failed++; continue; }
            $cache[$slug] = ['sha' => $e['sha'], 'doc' => proj_doc($slug, $txt), 'ts' => null];
            $parsed++;
        }
        foreach (array_keys($cache) as $s) if (!isset($seen[$s])) unset($cache[$s]);
        $budget = PROJ_COMMITS_PER_TICK;
        foreach ($cache as $slug => &$x) {
            if ($x['ts'] !== null || $budget-- <= 0) continue;
            [$c, $cm] = gh('GET', '/repos/' . CTL_REPO . '/commits?sha=' . CTL_BRANCH . '&per_page=10&path=' . rawurlencode(PROJ_ORCH . '/' . $slug . '/IDEA-ORCH.md'));
            if ($c !== 200) continue;
            $x['ts'] = 0;
            foreach ((array) $cm as $k) {
                if (str_contains((string) ($k['commit']['message'] ?? ''), PROJ_BULK_MARK)) continue;
                $x['ts'] = (int) strtotime((string) ($k['commit']['committer']['date'] ?? '')); break;
            }
        }
        unset($x);
        proj_write('docs.json', $cache);
        $cats = json_decode((string) gh_file(PROJ_CATS), true);
        if (!is_array($cats)) $cats = proj_read('cats.json', []); else proj_write('cats.json', $cats);
        if (!$cats) { $st['error'] = 'categories file unreadable'; return; }
        $groups = proj_groups($cache, $cats);
        proj_write('projects.json', ['at' => time(), 'orch_groups' => $groups]);
        $st = ['at' => time(), 'ok' => true, 'docs' => count($cache), 'parsed' => $parsed, 'failed' => $failed,
               'pending_dates' => count(array_filter($cache, fn($x) => $x['ts'] === null))];
    } finally {
        proj_write('state.json', $st);
        flock($lock, LOCK_UN);
    }
}

/** Parity check on a checkout: php -r 'require "projects.php"; echo json_encode(projects_from_local($root));' */
function projects_from_local(string $root): array {
    $docs = [];
    foreach (glob($root . '/' . PROJ_ORCH . '/*/IDEA-ORCH.md') as $p) {
        $slug = basename(dirname($p));
        $docs[$slug] = ['doc' => proj_doc($slug, (string) file_get_contents($p)), 'ts' => null];
    }
    return proj_groups($docs, json_decode((string) file_get_contents($root . '/' . PROJ_CATS), true) ?: []);
}
