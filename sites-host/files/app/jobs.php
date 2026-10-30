<?php
// Scheduled jobs of sites.hohedoc.ir — the read-only work that used to wake GitHub Actions on a timer.
// Each job reads the site through its bridge (sync.php, signed) and writes only aggregates to the repo.
// Schedules live in DH_JOBS; tick.php runs whatever is due. Nothing here writes to a site.
require_once __DIR__ . '/lib.php';

// name => [site, weekday (0=Sun … 6=Sat, null = every day), UTC hour, UTC minute]
const DH_JOBS = [
    'rum-digest' => ['stylistcrm', 6, 23, 17],   // was lilium-rum-digest.yml, cron '17 23 * * 6' (Sun 02:47 Tehran)
];

// Read files from a site through the bridge's snapshot action. Returns [path => raw bytes] (missing = absent).
function dh_snapshot(array $site, string $env, array $files): array {
    [$code, $j, $err] = dh_post($site, $env, ['action' => 'snapshot', 'ref' => 'main', 'files' => array_values($files)]);
    if ($code !== 200 || !is_array($j['results'] ?? null)) throw new RuntimeException("snapshot $env: HTTP $code " . ($err ?: (string) ($j['error'] ?? '')));
    $out = [];
    foreach ($j['results'] as $r) {
        if (empty($r['ok']) || !isset($r['b64'])) continue;
        $raw = base64_decode((string) $r['b64'], true);
        if ($raw === false || hash('sha256', $raw) !== ($r['sha256'] ?? '')) continue;
        $out[(string) $r['file']] = $raw;
    }
    return $out;
}

// ---------------------------------------------------------------- rum-digest (port of .github/scripts/rum-digest.py)
// Field measurements (RUM) → counts and percentiles only. Raw rows never leave this process; any cell with
// fewer than MIN_CELL samples is hidden so no branch or device can be singled out.
const RUM_MIN_CELL = 20, RUM_MIN_P75 = 30, RUM_CAP = 1500000, RUM_STALE_GRACE = 900;
const RUM_METRICS = ['ttfb', 'fcp', 'dcl', 'load'];

function rum_pct(array $vals, float $p): ?float {
    sort($vals);
    $n = count($vals);
    if (!$n) return null;
    $k = ($n - 1) * $p; $lo = (int) floor($k); $hi = min($lo + 1, $n - 1);
    return round($vals[$lo] + ($vals[$hi] - $vals[$lo]) * ($k - $lo), 1, PHP_ROUND_HALF_EVEN);   // Python round() is half-to-even
}

function rum_cells(array $rows, callable $key): array {
    $g = [];
    foreach ($rows as $r) $g[$key($r)][] = $r;
    ksort($g, SORT_STRING);
    $out = []; $hidden = 0;
    foreach ($g as $k => $rs) {
        if (count($rs) < RUM_MIN_CELL) { $hidden++; continue; }
        $c = ['n' => count($rs)];
        foreach (RUM_METRICS as $m) {
            $vals = [];
            foreach ($rs as $r) if (isset($r[$m]) && (is_int($r[$m]) || is_float($r[$m]))) $vals[] = (float) $r[$m];
            $c[$m . '_p50'] = rum_pct($vals, 0.5);
            $c[$m . '_p75'] = count($vals) >= RUM_MIN_P75 ? rum_pct($vals, 0.75) : null;
        }
        $out[(string) $k] = $c;
    }
    return [$out, $hidden];
}

function rum_digest(array $days, array $raw): array {
    sort($days);
    $alerts = []; $perDay = []; $rows = [];
    foreach ($days as $d) {
        $f = "pwa/public_html/storage/rum/$d.jsonl";
        if (!isset($raw[$f])) { $perDay[$d] = ['rows' => 0, 'bytes' => 0]; $alerts[] = "روزِ $d: هیچ ردیفی نرسید — حکم «نامعلوم»"; continue; }
        $n = 0;
        foreach (preg_split('/\r?\n/', $raw[$f]) as $line) {
            $r = json_decode($line, true);
            if (is_array($r) && $r !== [] && array_keys($r) !== range(0, count($r) - 1)) { $rows[] = $r; $n++; }
        }
        $size = strlen($raw[$f]);
        $perDay[$d] = ['rows' => $n, 'bytes' => $size];
        if ($size >= RUM_CAP * 0.95) $alerts[] = "روزِ $d: فایل به سقفِ ۱٫۵ مگابایت رسید — ردیف‌های بعدی دور ریخته شده‌اند";
    }
    $staleBad = []; $staleAny = 0;
    foreach ($rows as $r) {
        if (!in_array($r['stale'] ?? null, [1, true, 1.0], true)) continue;   // Python: r.get('stale') != 1 (True == 1 too)
        $staleAny++;
        $he = DateTime::createFromFormat('!Y-m-d\TH', (string) ($r['h'] ?? ''), new DateTimeZone('UTC'));
        $bs = isset($r['build_server']) && is_numeric($r['build_server']) ? (int) $r['build_server'] : null;
        if (in_array($r['nav'] ?? '', ['navigate', 'reload'], true) && $he && $bs !== null && $he->getTimestamp() >= $bs + RUM_STALE_GRACE) {
            $k = sprintf('%s %s / %s %s', rum_s($r['os'] ?? null), $r['osv'] ?? '', rum_s($r['br'] ?? null), $r['brv'] ?? '');
            $staleBad[$k] = ($staleBad[$k] ?? 0) + 1;
        }
    }
    if ($staleBad) $alerts[] = 'نسخهٔ کهنه پس از مهلتِ ۱۵ دقیقه: ' . array_sum($staleBad) . ' ردیف';
    [$byPage, $hp] = rum_cells($rows, fn($r) => ($r['page'] ?? '') ?: '?');
    [$byDevice, $hd] = rum_cells($rows, fn($r) => sprintf('%s %s / %s %s%s', rum_s($r['os'] ?? null), $r['osv'] ?? '', rum_s($r['br'] ?? null), $r['brv'] ?? '', !empty($r['pwa']) ? ' / app' : ''));
    [$byNet, $hn] = rum_cells($rows, fn($r) => ($r['net'] ?? '') ?: '?');
    return [
        'utc' => gmdate('Y-m-d\TH:i:s\Z'), 'days' => $days, 'per_day' => $perDay, 'rows' => count($rows),
        'freshness' => ['stale_rows_total' => $staleAny, 'stale_after_grace' => (object) $staleBad,
                        'verdict' => $staleBad ? 'fail' : ($rows ? 'ok' : 'unknown')],
        'by_page' => (object) $byPage, 'by_device' => (object) $byDevice, 'by_network' => (object) $byNet,
        'hidden_cells_under_' . RUM_MIN_CELL => ['page' => $hp, 'device' => $hd, 'network' => $hn],
        'alerts' => $alerts,
        'note' => 'فقط جمع؛ ردیفِ خام کامیت نمی‌شود. زمان‌ها میلی‌ثانیه. p75 فقط با n>=' . RUM_MIN_P75 . '.',
        'channel' => 'sites-host',
    ];
}

// Python's str(None) is "None"; keep the device keys byte-identical to the old digests.
function rum_s($v): string { return $v === null ? 'None' : (string) $v; }

function job_rum_digest(array $site, int $ndays = 7): array {
    $days = []; $files = [];
    for ($i = 0; $i < $ndays; $i++) {            // today (partial) counts too, like the workflow
        $d = gmdate('Y-m-d', time() - 86400 * $i);
        $days[] = $d; $files[] = "pwa/public_html/storage/rum/$d.jsonl";
    }
    $rep = rum_digest($days, dh_snapshot($site, 'pwa', $files));
    $name = 'speed-results/rum/digest-' . $rep['days'][0] . '_' . end($rep['days']) . '.json';
    dh_put_file($site, $name, json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'rum-digest: ' . implode(' ', $rep['days']) . ' (sites-host)');
    return ['file' => $name, 'rows' => $rep['rows'], 'freshness' => $rep['freshness']['verdict'], 'alerts' => $rep['alerts']];
}

// ---------------------------------------------------------------- scheduler
// A job is due when its slot (this week's or today's day/hour/minute) has passed and it has not run since.
function dh_job_due(array $spec, int $last, int $now): bool {
    [, $wday, $h, $m] = $spec;
    $slot = gmmktime($h, $m, 0, (int) gmdate('n', $now), (int) gmdate('j', $now), (int) gmdate('Y', $now));
    if ($wday !== null) $slot -= 86400 * (((int) gmdate('w', $now) - $wday + 7) % 7);
    if ($slot > $now) $slot -= $wday !== null ? 7 * 86400 : 86400;
    return $last < $slot;
}

function dh_run_jobs(?string $only = null): array {
    $done = [];
    $state = dh_state()['jobs'] ?? [];
    foreach (DH_JOBS as $name => $spec) {
        if ($only !== null ? $only !== $name : !dh_job_due($spec, (int) ($state[$name]['at'] ?? 0), time())) continue;
        $site = dh_sites()[$spec[0]];
        try {
            $r = ['ok' => true] + ($name === 'rum-digest' ? job_rum_digest($site) : []);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'error' => substr($e->getMessage(), 0, 200)];
        }
        $state[$name] = ['at' => time(), 'utc' => gmdate('c')] + $r;
        dh_state_set('jobs', $state);
        dh_log("job $name -> " . ($r['ok'] ? 'ok' : 'error'));
        $done[$name] = $r;
    }
    return $done;
}
