<?php
// «سشن‌ها» of the My-Control-Project app (app v64, owner decision 1405-08-28): a READ-ONLY list of the live-session
// ledgers in the private repo (Memory-Workflow/live-sessions/<task>/session.json — github-backed-session-ledger).
// Built by the 5-minute cron like projects.php: one contents call + one tree call per tick, and only session.json
// blobs whose sha changed are fetched again. Output: var/sessions/sessions.json, served by api.php?op=sessions (signed).
// ⛔ Read-only: this file never writes to GitHub, never fires or relays a session, and never touches the trainer
//    cycle (pull, extraction, step 3, step 4) — it only shows what the ledgers already say.
// Only whitelisted fields leave the host; a URL is passed only when it is a claude.ai code-session URL.
require_once __DIR__ . '/lib.php';

const SESS_DIR = 'Memory-Workflow/live-sessions';
const SESS_VAR = 'sessions';
const SESS_FIELDS = ['created_utc', 'last_relay_utc', 'listener', 'listener_heartbeat_utc', 'listener_off_reason'];

/** A ledger folder name the app may show: a trainer bundle (b123) or a task (t-…). */
function sess_task_ok(string $t): bool { return (bool) preg_match('/^(b[0-9]{1,8}|t-[a-z0-9-]{1,60})$/', $t); }

/** One row for the app, or null for a name that is not a ledger. $j = decoded session.json, or null when absent. */
function sess_row(string $task, ?array $j): ?array {
    if (!sess_task_ok($task)) return null;
    $url = null;
    // same rule as op=hub: a fired session may come back as cse_…; the openable URL is always session_…
    if (preg_match('#^https://claude\.ai/code/(?:session|cse)_([A-Za-z0-9]+)$#', (string) ($j['url'] ?? ''), $m))
        $url = 'https://claude.ai/code/session_' . $m[1];
    $row = ['task' => $task, 'url' => $url];
    foreach (SESS_FIELDS as $f) {
        $v = $j[$f] ?? null;
        $row[$f] = is_scalar($v) && (string) $v !== '' ? mb_substr((string) $v, 0, 200) : null;
    }
    return $row;
}

/** [task => session.json|null] ⇒ sorted rows. */
function sessions_build(array $folders): array {
    $out = [];
    foreach ($folders as $t => $j) { $r = sess_row((string) $t, is_array($j) ? $j : null); if ($r) $out[] = $r; }
    usort($out, fn($a, $b) => strcmp($a['task'], $b['task']));
    return $out;
}

function sess_var(string $n): string { $d = ctl_var(SESS_VAR); if (!is_dir($d)) mkdir($d, 0700, true); return $d . '/' . $n; }
function sess_read(string $n, $def) { $x = @json_decode((string) @file_get_contents(sess_var($n)), true); return is_array($x) ? $x : $def; }
function sess_write(string $n, $data): void {
    $f = sess_var($n);
    file_put_contents($f . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($f . '.tmp', $f);
}

/** Every cron tick: list the ledger folders, re-read only changed session.json blobs, write var/sessions/sessions.json. */
function sessions_tick(): void {
    $lock = fopen(sess_var('tick.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    $st = ['at' => time(), 'ok' => false];
    try {
        [$c, $dir] = gh('GET', '/repos/' . CTL_REPO . '/contents/' . rawurlencode_path(dirname(SESS_DIR)) . '?ref=' . CTL_BRANCH);
        $tsha = null;
        foreach ((array) $dir as $e) if (($e['name'] ?? '') === basename(SESS_DIR)) $tsha = $e['sha'] ?? null;
        if ($c !== 200 || !$tsha) { $st['error'] = 'github ' . $c . ' (live-sessions folder)'; return; }
        [$c, $tree] = gh('GET', '/repos/' . CTL_REPO . '/git/trees/' . $tsha . '?recursive=1');
        if ($c !== 200) { $st['error'] = 'github ' . $c . ' (tree)'; return; }
        $cache = sess_read('blobs.json', []);
        $folders = []; $blobs = []; $fetched = 0; $failed = 0;
        foreach (($tree['tree'] ?? []) as $e) {
            $p = (string) ($e['path'] ?? '');
            if (($e['type'] ?? '') === 'tree' && !str_contains($p, '/')) $folders[$p] = null;
            elseif (($e['type'] ?? '') === 'blob' && preg_match('#^([^/]+)/session\.json$#', $p, $m)) $blobs[$m[1]] = (string) $e['sha'];
        }
        $keep = [];
        foreach ($blobs as $t => $sha) {
            if (!sess_task_ok($t)) continue;
            if (($cache[$t]['sha'] ?? '') !== $sha) {
                [$c, $j] = gh('GET', '/repos/' . CTL_REPO . '/git/blobs/' . $sha);
                $doc = ($c === 200 && isset($j['content'])) ? json_decode((string) base64_decode($j['content']), true) : null;
                if (!is_array($doc)) { $failed++; if (isset($cache[$t])) $keep[$t] = $cache[$t]; continue; }
                $cache[$t] = ['sha' => $sha, 'doc' => $doc]; $fetched++;
            }
            $keep[$t] = $cache[$t];
            $folders[$t] = $cache[$t]['doc'];
        }
        sess_write('blobs.json', $keep);
        $rows = sessions_build($folders);
        sess_write('sessions.json', ['at' => time(), 'sessions' => $rows]);
        $st = ['at' => time(), 'ok' => true, 'rows' => count($rows), 'fetched' => $fetched, 'failed' => $failed];
    } finally {
        sess_write('state.json', $st);
        flock($lock, LOCK_UN);
    }
}

/** Parity check on a checkout: php -r 'require "sessions.php"; echo json_encode(sessions_from_local($root));' */
function sessions_from_local(string $root): array {
    $folders = [];
    foreach (glob($root . '/' . SESS_DIR . '/*', GLOB_ONLYDIR) as $d) {
        $f = $d . '/session.json';
        $folders[basename($d)] = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    }
    return sessions_build($folders);
}
