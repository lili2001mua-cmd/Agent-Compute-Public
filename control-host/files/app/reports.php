<?php
// Chapter progress reports of My-Control-Project (app v65, owner decision 1405-08-29):
// Source of truth lives on GitHub inside each operational chapter:
//   Memory-Workflow/projects-hub/<project>/chapters/<CODE>/REPORT.md
// Every AI employee (Claude, Gemini, Codex, Grok, ...) appends a <= 8-line summary per completed step.
// Built by the 5-minute cron (like sessions.php) and served over the signed door POST api.php?op=chapter-report
// (works both directly via control.hohedoc.ir and through relay.liliumesorkh.ir?job=ctl without exposing private data publicly).
require_once __DIR__ . '/lib.php';

const REPORTS_DIR = 'Memory-Workflow/projects-hub';
const REPORTS_VAR = 'reports';
const REPORT_CODE_RE = '/^[A-Z]{3}-\d{2}$/';
const REPORT_PATH_RE = '#^Memory-Workflow/projects-hub/[a-z0-9-]+/chapters/([A-Z]{3}-\d{2})/REPORT\.md$#';
const REPORT_EMPTY_MARKER = '_هنوز گزارشی برای این گام ثبت نشده است._';

function report_code_ok(string $c): bool { return (bool) preg_match(REPORT_CODE_RE, $c); }

function report_path_ok(string $p, string $code): bool {
    return preg_match(REPORT_PATH_RE, $p, $m) === 1 && $m[1] === $code;
}

/** Convert Persian/Arabic digits to ASCII digits. */
function report_ascii_digits(string $s): string {
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    return str_replace($fa, $en, $s);
}

/** Parse one chapter REPORT.md into structured per-step entries. Pure function (testable without network). */
function report_parse(string $code, string $path, string $md, ?int $at = null): array {
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $parts = preg_split('/^##\s+/m', $md);
    $entries = [];
    for ($i = 1; $i < count($parts); $i++) {
        $chunk = $parts[$i];
        $nl = strpos($chunk, "\n");
        $heading = trim($nl === false ? $chunk : substr($chunk, 0, $nl));
        $rest = $nl === false ? '' : substr($chunk, $nl + 1);
        $num = null;
        if (preg_match('/<!--\s*step-num:\s*(\d+)\s*-->/', $rest, $m)) {
            $num = (int) $m[1];
            $rest = preg_replace('/<!--\s*step-num:\s*\d+\s*-->\s*/', '', $rest, 1);
        } elseif (preg_match('/گامِ?\s*([0-9۰-۹٠-٩]+)/u', $heading, $m)) {
            $num = (int) report_ascii_digits($m[1]);
        }
        if ($num === null || $num < 1) continue;
        $title = preg_replace('/^گامِ?\s*[0-9۰-۹٠-٩]+\s*[—–\-:]\s*/u', '', $heading);
        $employee = '';
        $model = '';
        $date = '';
        $approved = '';
        $state = '';
        if (preg_match('/<!--\s*(employee:[^>]+?)\s*-->/u', $rest, $m)) {
            foreach (preg_split('/\s*·\s*/u', $m[1]) as $part) {
                $pos = strpos($part, ':');
                if ($pos === false) continue;
                $k = strtolower(trim(substr($part, 0, $pos)));
                $v = trim(substr($part, $pos + 1));
                if ($k === 'employee') $employee = $v;
                elseif ($k === 'model') $model = $v;
                elseif ($k === 'date') $date = $v;
                elseif ($k === 'approved') $approved = $v;
                elseif ($k === 'state') $state = $v;
            }
            $rest = preg_replace('/<!--\s*employee:[^>]+-->\s*/u', '', $rest, 1);
        }
        $body = trim((string) $rest);
        $empty = ($body === '' || $body === REPORT_EMPTY_MARKER);
        if ($empty) {
            $body = '';
        } else {
            // Keep clean non-empty lines (max 12 lines safety cap; rule is <= 8 lines)
            $lines = array_values(array_filter(array_map('trim', explode("\n", $body)), fn($l) => $l !== ''));
            $body = implode("\n", array_slice($lines, 0, 12));
        }
        $entries[] = [
            'num' => $num,
            'title' => trim((string) $title),
            'employee' => $employee,
            'model' => $model,
            'date' => $date,
            'approved' => $approved,
            'state' => $state,
            'body' => $body,
            'empty' => $empty,
        ];
    }
    usort($entries, fn($a, $b) => $a['num'] <=> $b['num']);
    return [
        'code' => $code,
        'path' => $path,
        'github_url' => 'https://github.com/' . CTL_REPO . '/blob/' . CTL_BRANCH . '/' . $path,
        'at' => $at ?? time(),
        'entries' => $entries,
    ];
}

function rep_var(string $n): string { $d = ctl_var(REPORTS_VAR); if (!is_dir($d)) mkdir($d, 0700, true); return $d . '/' . $n; }
function rep_read(string $n, $def) { $x = @json_decode((string) @file_get_contents(rep_var($n)), true); return is_array($x) ? $x : $def; }
function rep_write(string $n, $data): void {
    $f = rep_var($n);
    file_put_contents($f . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($f . '.tmp', $f);
}

/** Every 5-minute cron tick: scan projects-hub chapters for REPORT.md, re-fetch only changed blobs. */
function reports_tick(): void {
    $lock = fopen(rep_var('tick.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    $st = ['at' => time(), 'ok' => false];
    try {
        [$c, $dir] = gh('GET', '/repos/' . CTL_REPO . '/contents/' . rawurlencode_path(dirname(REPORTS_DIR)) . '?ref=' . CTL_BRANCH);
        $tsha = null;
        foreach ((array) $dir as $e) if (($e['name'] ?? '') === basename(REPORTS_DIR)) $tsha = $e['sha'] ?? null;
        if ($c !== 200 || !$tsha) { $st['error'] = 'github ' . $c . ' (projects-hub folder)'; return; }
        [$c, $tree] = gh('GET', '/repos/' . CTL_REPO . '/git/trees/' . $tsha . '?recursive=1');
        if ($c !== 200) { $st['error'] = 'github ' . $c . ' (tree)'; return; }
        $cache = rep_read('blobs.json', []);
        $keep = []; $reports = []; $fetched = 0; $failed = 0;
        foreach (($tree['tree'] ?? []) as $e) {
            if (($e['type'] ?? '') !== 'blob') continue;
            $p = (string) ($e['path'] ?? '');
            if (!preg_match('#^([a-z0-9-]+)/chapters/([A-Z]{3}-\d{2})/REPORT\.md$#', $p, $m)) continue;
            $code = $m[2];
            $fullPath = REPORTS_DIR . '/' . $p;
            $sha = (string) ($e['sha'] ?? '');
            if (($cache[$code]['sha'] ?? '') !== $sha || ($cache[$code]['path'] ?? '') !== $fullPath) {
                [$bc, $bj] = gh('GET', '/repos/' . CTL_REPO . '/git/blobs/' . $sha);
                $raw = ($bc === 200 && isset($bj['content'])) ? (string) base64_decode($bj['content']) : null;
                if ($raw === null) { $failed++; if (isset($cache[$code])) { $keep[$code] = $cache[$code]; $reports[$code] = $cache[$code]['doc']; } continue; }
                $doc = report_parse($code, $fullPath, $raw, time());
                $cache[$code] = ['sha' => $sha, 'path' => $fullPath, 'doc' => $doc];
                $fetched++;
            }
            $keep[$code] = $cache[$code];
            $reports[$code] = $cache[$code]['doc'];
        }
        ksort($reports);
        rep_write('blobs.json', $keep);
        rep_write('reports.json', ['at' => time(), 'reports' => $reports]);
        $st = ['at' => time(), 'ok' => true, 'count' => count($reports), 'fetched' => $fetched, 'failed' => $failed];
    } finally {
        rep_write('state.json', $st);
        flock($lock, LOCK_UN);
    }
}

/** Return one chapter report: cron cache first, then live GitHub fetch when hintPath is valid (fresh chapter or pre-tick). */
function report_get(string $code, string $hintPath = ''): ?array {
    if (!report_code_ok($code)) return null;
    $all = rep_read('reports.json', ['reports' => []]);
    if (isset($all['reports'][$code]) && is_array($all['reports'][$code])) return $all['reports'][$code];
    if ($hintPath !== '' && report_path_ok($hintPath, $code)) {
        $raw = gh_file($hintPath);
        if ($raw !== null) {
            $doc = report_parse($code, $hintPath, $raw, time());
            $all['reports'][$code] = $doc;
            $all['at'] = $all['at'] ?? time();
            rep_write('reports.json', $all);
            return $doc;
        }
    }
    return null;
}

/** Parity check on a local checkout of Agent-Project-Backup. */
function reports_from_local(string $root): array {
    $out = [];
    foreach (glob($root . '/' . REPORTS_DIR . '/*/chapters/*/REPORT.md') ?: [] as $f) {
        $rel = ltrim(str_replace('\\', '/', substr($f, strlen(rtrim($root, '/')))), '/');
        if (!preg_match(REPORT_PATH_RE, $rel, $m)) continue;
        $code = $m[1];
        $out[$code] = report_parse($code, $rel, (string) file_get_contents($f), time());
    }
    ksort($out);
    return $out;
}
