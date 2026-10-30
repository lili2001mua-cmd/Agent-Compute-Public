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

// ---------------------------------------------------------------- speed probe (port of .github/scripts/speed-probe.py)
// Read-only GET/HEAD timings and cache headers of the live site, measured from this host (Germany) — the same
// vantage point the GitHub runner had. Nothing personal is kept: no cookie, no IP, no page body.
const PROBE_PATHS = ['/auth/login', '/assets/css/ticktime.css', '/assets/js/jquery.min.js', '/assets/js/custom.js',
    '/assets/js/chart.js', '/assets/css/toastr.css', '/assets/js/toastr.min.js', '/assets/js/jalalidatepicker.min.js',
    '/assets/css/jalalidatepicker.min.css', '/assets/stylist/logo.jpg', '/favicon.ico', '/manifest.webmanifest',
    '/manifest.php', '/service-worker.js', '/version.php', '/assets/js/version-check.js'];
const PROBE_HDRS = ['cache-control', 'expires', 'etag', 'last-modified', 'content-encoding', 'vary', 'server',
    'x-litespeed-cache', 'x-cache', 'x-cache-status', 'age', 'cf-cache-status', 'x-proxy-cache',
    'content-type', 'content-length', 'strict-transport-security', 'x-powered-by'];

// One request; returns timings like curl -w (seconds) and, when asked, the selected response headers or the body.
function probe_req(string $url, array $hdr = [], bool $follow = false, string $want = 'times') {
    $h = []; $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_HTTPHEADER => $want === 'times' ? array_merge(['Cache-Control: no-cache'], $hdr) : $hdr,
        CURLOPT_ENCODING => null, CURLOPT_USERAGENT => 'curl/8',
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$h) {
            $t = trim($line);
            if (strncmp($t, 'HTTP/', 5) === 0) { $h = ['status' => $t]; }
            elseif (strpos($t, ':') !== false) { [$k, $v] = explode(':', $t, 2); $k = strtolower(trim($k)); if (in_array($k, PROBE_HDRS, true)) $h[$k] = trim($v); }
            return strlen($line);
        }]);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); return $want === 'body' ? '' : ['error' => substr($e, 0, 200)]; }
    $i = curl_getinfo($ch); curl_close($ch);
    if ($want === 'headers') return $h;
    if ($want === 'body') return (string) $body;
    $ver = [1 => '1', 2 => '1.1', 3 => '2', 30 => '3'][(int) ($i['http_version'] ?? 0)] ?? '0';
    return ['code' => (int) $i['http_code'], 'dns' => $i['namelookup_time'], 'connect' => $i['connect_time'],
            'tls' => $i['appconnect_time'] ?? 0, 'ttfb' => $i['starttransfer_time'],
            'total' => $i['total_time'], 'size' => (int) $i['size_download'], 'redirects' => (int) $i['redirect_count'], 'http' => $ver];
}

function probe_med(array $xs, string $k): ?float {
    $v = [];
    foreach ($xs as $x) if (is_array($x) && isset($x[$k])) $v[] = (float) $x[$k];
    if (!$v) return null;
    sort($v); $n = count($v);
    $m = $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    return round($m, 4, PHP_ROUND_HALF_EVEN);
}

function job_speed_probe(array $site, string $env, string $label, int $runs = 5): array {
    $u = parse_url(dh_endpoint($site, $env));
    $host = $u['host']; $base = 'https://' . $host;
    $gzip = ['Accept-Encoding: gzip, deflate, br'];
    $rep = ['base' => $base, 'label' => $label, 'utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'runner_note' => 'sites.hohedoc.ir (Germany, outside Iran): network times include host-to-site distance; server and header facts are real.',
            'items' => []];
    foreach (PROBE_PATHS as $p) {
        $url = $base . $p;
        $item = ['path' => $p, 'headers_compressed' => probe_req($url, $gzip, false, 'headers')];
        $gz = []; for ($i = 0; $i < $runs; $i++) $gz[] = probe_req($url, $gzip);
        $raw = probe_req($url);
        $item['runs'] = count($gz);
        foreach (['dns', 'connect', 'tls', 'ttfb', 'total'] as $k) $item['median_' . $k] = probe_med($gz, $k);
        $item['code'] = $gz[0]['code'] ?? null;
        $item['http_version'] = $gz[0]['http'] ?? null;
        $item['bytes_on_wire'] = $gz[0]['size'] ?? null;
        $item['bytes_uncompressed'] = $raw['size'] ?? null;
        $et = $item['headers_compressed']['etag'] ?? null; $lm = $item['headers_compressed']['last-modified'] ?? null;
        if ($et || $lm) {
            $c = probe_req($url, [$et ? 'If-None-Match: ' . $et : 'If-Modified-Since: ' . $lm]);
            $item['revalidate_code'] = $c['code'] ?? null; $item['revalidate_total'] = $c['total'] ?? null;
        }
        $errs = array_values(array_filter(array_map(fn($x) => $x['error'] ?? null, $gz)));
        if ($errs) $item['errors'] = array_slice($errs, 0, 2);
        $rep['items'][] = $item;
    }
    $tok = sprintf('probe%06d', random_int(0, 999999));
    $fc = [];
    foreach (['A1' => '?v=probeA', 'A2' => '?v=probeA', 'B_new' => '?v=' . $tok] as $n => $q) $fc[$n] = probe_req($base . '/assets/js/custom.js' . $q, [], false, 'headers');
    $rep['front_cache_key_test'] = $fc;
    $page = $env === 'help' ? '/login' : '/auth/login';
    $html = probe_req($base . $page, [], false, 'body');
    preg_match_all('#["\'](/(?:public/)?assets/[^"\'?]+\.(?:js|css))(\?v=\d+)?["\']#', $html, $m, PREG_SET_ORDER);
    $sb = json_decode(probe_req($base . '/version.php', [], false, 'body'), true)['build'] ?? null;
    $unv = array_values(array_unique(array_map(fn($r) => $r[1], array_filter($m, fn($r) => empty($r[2])))));
    sort($unv);
    $rep['freshness_test'] = ['page' => $page, 'asset_refs' => count($m), 'versioned' => count(array_filter($m, fn($r) => !empty($r[2]))),
        'unversioned' => $unv, 'page_build' => preg_match('/name="app-build" content="(\d+)"/', $html, $mb) ? (int) $mb[1] : null, 'server_build' => $sb];
    $rep['redirect_http_root'] = probe_req('http://' . $host . '/', [], true);
    $name = 'speed-results/probe-' . $env . '-' . $label . '-' . gmdate('Ymd-His') . '.json';
    dh_put_file($site, $name, json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "speed-probe: $env $label (sites-host)");
    return ['action' => 'probe', 'env' => $env, 'ok' => true, 'file' => $name, 'freshness' => $rep['freshness_test']];
}
