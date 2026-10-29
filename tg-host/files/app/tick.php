<?php
// Cron entry, every minute: /usr/local/bin/php /home/<user>/domains/tg.hohedoc.ir/app/tick.php
// Lease queue (panel items ن۷/ن۸): ONE flock held for the whole tick, taken before any library loads, so
// overlapping minute ticks exit at almost no cost. A job is claimed by rename queue/ -> running/. Any file
// still in running/ when we hold the lock was killed mid-run: resumed (retry=true, attempt cap) or failed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = (string) realpath(__DIR__ . '/..');
@mkdir($base . '/var', 0700, true);
$lock = fopen($base . '/var/tick.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
file_put_contents($base . '/var/last-tick.txt', (string) time(), LOCK_EX);

require __DIR__ . '/lib.php';
@set_time_limit(0);
@ini_set('memory_limit', '512M');
$t0 = time();
$reg = include __DIR__ . '/jobs/registry.php';
require_once __DIR__ . '/jobs/phase1.php';
foreach (glob(__DIR__ . '/jobs/phase[2-9]*.php') ?: [] as $f) require_once $f;
if (function_exists('tg_phase2_pre')) tg_phase2_pre();

// media dir: no listing (deploy refuses dot-files, so the tick writes it)
$m = tg_dir(TG_BASE . '/public_html/m'); @chmod($m, 0755);
if (!is_file($m . '/.htaccess')) file_put_contents($m . '/.htaccess', "Options -Indexes\n");
if (!is_file($m . '/index.html')) file_put_contents($m . '/index.html', '');

// sweep: expired media, old job folders, orphan temp files
foreach (glob($m . '/*', GLOB_ONLYDIR) ?: [] as $d) {
    $exp = (int) @file_get_contents($d . '/.expires');
    if (($exp && $exp < time()) || (!$exp && filemtime($d) < time() - TG_MEDIA_TTL_S)) tg_rm_r($d);
}
foreach (glob(tg_var('jobs') . '/*', GLOB_ONLYDIR) ?: [] as $d) { if (filemtime($d) < time() - 7 * 86400) tg_rm_r($d); }
foreach (glob(tg_var('queue') . '/*.tmp') ?: [] as $f) { if (filemtime($f) < time() - 3600) @unlink($f); }

// recover killed jobs
tg_dir(tg_var('queue')); tg_dir(tg_var('running'));
foreach (glob(tg_var('running') . '/*.json') ?: [] as $f) {
    $j = tg_read_json($f);
    if (!$j) { @unlink($f); continue; }
    $st = tg_read_json(tg_job_dir($j['id']) . '/status.json') ?? [];
    $retry = (bool) ($reg[$j['job']]['retry'] ?? false);
    if ($retry && ($j['attempt'] ?? 1) < 3) {
        rename($f, tg_var('queue') . '/' . basename($f));
        tg_status($j['id'], ['state' => 'queued', 'note' => 'resumed after kill']);
    } else {
        @unlink($f);
        tg_status($j['id'], ['state' => 'failed', 'error' => 'killed by host', 'killed_after_s' => isset($st['started_at'], $st['heartbeat_at']) ? $st['heartbeat_at'] - $st['started_at'] : null]);
    }
}

// drain until the budget runs out (login jobs first)
while (time() - $t0 < TG_TICK_BUDGET_S) {
    $files = glob(tg_var('queue') . '/*.json') ?: [];
    usort($files, fn($x, $y) => [!str_contains(basename($x), '-login'), filemtime($x)] <=> [!str_contains(basename($y), '-login'), filemtime($y)]);
    $pick = null;
    foreach ($files as $f) { $j = tg_read_json($f); if ($j && ($j['not_before'] ?? 0) <= time()) { $pick = [$f, $j]; break; } }
    if (!$pick) break;
    [$f, $j] = $pick;
    $run = tg_var('running') . '/' . basename($f);
    if (!@rename($f, $run)) continue;
    $j['attempt'] = ($j['attempt'] ?? 0) + 1;
    tg_write_json($run, $j);
    $id = $j['id'];
    tg_status($id, ['state' => 'running', 'started_at' => time(), 'heartbeat_at' => time(), 'pid' => getmypid(), 'attempt' => $j['attempt']]);
    $hb = function (array $p) use ($id) { tg_status($id, $p + ['heartbeat_at' => time()]); };
    try {
        $fn = 'job_' . $j['job'];
        if (!isset($reg[$j['job']]) || !function_exists($fn)) throw new RuntimeException('unknown job');
        $res = $fn($j['args'] ?? [], $hb, (string) ($j['role'] ?? 'read'));
        tg_write_json(tg_job_dir($id) . '/result.json', $res);
        tg_status($id, ['state' => 'done', 'finished_at' => time()]);
        tg_log('jobs', $j['job'] . ' done');
    } catch (Throwable $e) {
        $msg = preg_replace('/[A-Za-z0-9_\-]{20,}/', '…', mb_substr($e->getMessage(), 0, 200));
        // where it broke: the first frames inside our own app/ code (no arguments, so no secret can leak)
        $at = [];
        foreach (array_merge([['file' => $e->getFile(), 'line' => $e->getLine()]], $e->getTrace()) as $f) {
            if (isset($f['file']) && str_starts_with($f['file'], TG_BASE . '/app/')) $at[] = basename($f['file']) . ':' . $f['line'];
            if (count($at) >= 3) break;
        }
        tg_status($id, ['state' => 'failed', 'error' => get_class($e) . ': ' . $msg, 'at' => $at, 'finished_at' => time()]);
        tg_log('jobs', $j['job'] . ' failed ' . get_class($e));
    }
    @unlink($run);
    if (!empty($reg[$j['job']]['tg'])) break; // one MadelineProto instance per process; shutdown serializes it
}
