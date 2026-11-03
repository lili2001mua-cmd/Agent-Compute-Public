<?php
// On-demand site operations of sites.hohedoc.ir that used to be GitHub Actions workflows:
//   pull     — copy files from the live site back into the repo (was pull-from-host.yml, over FTP)
//   dbdump   — a gzip dump of the site's database (was lilium-dbdump.yml); never goes into git
//   parity   — test site vs production site: files, schema, migration ledger (was lilium-parity.yml)
// Each is a queue group, so each needs the user's approval stamp like any other request.
require_once __DIR__ . '/lib.php';

const DH_PULL_MAX_FILES = 2000;             // per request; ask again with a narrower prefix for more
const DH_DUMP_TTL = 6 * 3600;               // a dump link lives 6 hours and works once

// ---------------------------------------------------------------- pull
// files = path prefixes inside the repo, e.g. ["pwa/public_html/core/"]. The site's manifest picks the files,
// the bridge's snapshot reads them, and one commit writes every file whose git blob differs from main.
function dh_pull(array $site, string $env, array $prefixes, string $id): array {
    $lines = []; $start = 0;
    while (true) {
        [$code, $j, $e] = dh_post($site, $env, ['action' => 'manifest', 'ref' => 'main', 'start' => $start, 'limit' => 400, 'files' => []]);
        if ($code !== 200 || empty($j['ok'])) throw new RuntimeException("pull manifest $env: " . ($e ?: ($j['error'] ?? "HTTP $code")));
        foreach ((array) $j['lines'] as $l) $lines[] = (string) $l;
        if (!empty($j['done'])) break;
        $next = (int) ($j['next'] ?? $start);
        if ($next <= $start) throw new RuntimeException('pull manifest: no progress');
        $start = $next;
    }
    $want = [];
    foreach ($lines as $l) {
        $p = ltrim(substr($l, 66), './');                      // "<sha256>  ./pwa/public_html/x"
        foreach ($prefixes as $pre) if (strncmp($p, $pre, strlen($pre)) === 0) { $want[] = $p; break; }
    }
    if (count($want) > DH_PULL_MAX_FILES) throw new RuntimeException('pull: ' . count($want) . ' files match; narrow the prefix (max ' . DH_PULL_MAX_FILES . ')');
    // Repo side: git blob sha1 of every path on main, so only real changes become blobs.
    [$c, $ref] = dh_gh($site['repo'], 'GET', '/git/ref/heads/main');
    $head = $ref['object']['sha'] ?? '';
    [$c2, $cm] = dh_gh($site['repo'], 'GET', '/git/commits/' . $head);
    [$c3, $tree] = dh_gh($site['repo'], 'GET', '/git/trees/' . ($cm['tree']['sha'] ?? '') . '?recursive=1');
    if ($c !== 200 || $c2 !== 200 || $c3 !== 200 || !empty($tree['truncated'])) throw new RuntimeException('pull: cannot read the repo tree');
    $have = [];
    foreach ($tree['tree'] as $t) if ($t['type'] === 'blob') $have[$t['path']] = $t['sha'];
    $entries = []; $missing = [];
    foreach (array_chunk($want, 40) as $batch) {
        $got = dh_snapshot_site($site, $env, $batch);
        foreach ($batch as $p) {
            if (!isset($got[$p])) { $missing[] = $p; continue; }
            $sha1 = sha1('blob ' . strlen($got[$p]) . "\0" . $got[$p]);
            if (($have[$p] ?? '') === $sha1) continue;
            [$bc, $blob] = dh_gh($site['repo'], 'POST', '/git/blobs', ['content' => base64_encode($got[$p]), 'encoding' => 'base64']);
            if ($bc !== 201) throw new RuntimeException("pull: blob $p HTTP $bc");
            $entries[] = ['path' => $p, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha']];
        }
    }
    $commit = null;
    if ($entries) {
        [$tc, $nt] = dh_gh($site['repo'], 'POST', '/git/trees', ['base_tree' => $cm['tree']['sha'], 'tree' => $entries]);
        [$cc, $nc] = dh_gh($site['repo'], 'POST', '/git/commits', ['message' => "sync: pull " . count($entries) . " file(s) from $env (sites-host: $id)",
            'tree' => $nt['sha'] ?? '', 'parents' => [$head]]);
        [$rc] = dh_gh($site['repo'], 'PATCH', '/git/refs/heads/main', ['sha' => $nc['sha'] ?? '', 'force' => false]);
        if ($tc !== 201 || $cc !== 201 || $rc !== 200) throw new RuntimeException("pull: commit failed (tree $tc, commit $cc, ref $rc)");
        $commit = $nc['sha'];
    }
    return ['action' => 'pull', 'env' => $env, 'ok' => !$missing, 'matched' => count($want), 'changed' => count($entries),
            'commit' => $commit] + ($missing ? ['missing' => array_slice($missing, 0, 50)] : []);
}

// Snapshot keyed by repo path (the bridge answers with the same repo-relative paths it was asked for).
function dh_snapshot_site(array $site, string $env, array $files): array {
    [$code, $j, $err] = dh_post($site, $env, ['action' => 'snapshot', 'ref' => 'main', 'files' => array_values($files)]);
    if ($code !== 200 || !is_array($j['results'] ?? null)) throw new RuntimeException("snapshot $env: HTTP $code " . ($err ?: (string) ($j['error'] ?? '')));
    $out = [];
    foreach ($j['results'] as $r) {
        if (empty($r['ok']) || !isset($r['b64'])) continue;
        $raw = base64_decode((string) $r['b64'], true);
        if ($raw !== false && hash('sha256', $raw) === ($r['sha256'] ?? '')) $out[(string) $r['file']] = $raw;
    }
    return $out;
}

// ---------------------------------------------------------------- dbdump
// The dump holds customer data, so it never touches git: it stays on this host outside public_html and the
// result file in the private repo carries a one-time link that expires after DH_DUMP_TTL.
function dh_dbdump(array $site, string $env, string $id): array {
    $dir = dh_var('dumps');
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $tok = bin2hex(random_bytes(20));
    $tmp = "$dir/$tok.part";
    $payload = ['action' => 'dbdump', 'ref' => 'main', 'files' => [], 'ts' => time()];
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $fh = fopen($tmp, 'wb');
    $name = '';
    $ch = curl_init(dh_endpoint($site, $env));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 900,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Signature: sha256=' . hash_hmac('sha256', $body, dh_secret($site['secret']))],
        CURLOPT_USERAGENT => 'Lilium-DeployHost',
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$name) {
            if (preg_match('/filename="([^"]+)"/', $line, $m)) $name = basename($m[1]);
            return strlen($line);
        }]);
    $ok = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch); fclose($fh);
    $magic = (string) @file_get_contents($tmp, false, null, 0, 2);
    $size = (int) @filesize($tmp);
    if (!$ok || $code !== 200 || $magic !== "\x1f\x8b" || $size < 100) {
        $head = substr((string) @file_get_contents($tmp, false, null, 0, 300), 0, 300);
        @unlink($tmp);
        return ['action' => 'dbdump', 'env' => $env, 'ok' => false, 'http' => $code,
                'error' => $err ?: ($magic === "\x1f\x8b" ? 'too small' : 'not gzip: ' . preg_replace('/[^\x20-\x7e]/', '?', $head))];
    }
    $name = $name ?: sprintf('dbdump-%s-%s.sql.gz', $env, gmdate('Ymd-His'));
    rename($tmp, "$dir/$tok.gz");
    file_put_contents("$dir/$tok.json", json_encode(['name' => $name, 'expires' => time() + DH_DUMP_TTL]), LOCK_EX);
    $gz = @gzopen("$dir/$tok.gz", 'rb'); $tables = 0;
    if ($gz) { while (($l = gzgets($gz)) !== false) if (strncmp($l, 'CREATE TABLE', 12) === 0) $tables++; gzclose($gz); }
    dh_log("$id dbdump $env -> $size bytes");
    return ['action' => 'dbdump', 'env' => $env, 'ok' => true, 'bytes' => $size, 'tables' => $tables, 'name' => $name,
            'download' => 'https://sites.hohedoc.ir/get.php?t=' . $tok, 'expires_utc' => gmdate('c', time() + DH_DUMP_TTL),
            'note' => 'one download, then deleted; never committed (customer data)'];
}

function dh_dumps_cleanup(): void {
    foreach (glob(dh_var('dumps') . '/*.json') ?: [] as $m) {
        $j = json_decode((string) file_get_contents($m), true);
        if (($j['expires'] ?? 0) < time()) { @unlink(substr($m, 0, -5) . '.gz'); @unlink($m); }
    }
    foreach (glob(dh_var('dumps') . '/*.part') ?: [] as $p) if (filemtime($p) < time() - 3600) @unlink($p);
}

// ---------------------------------------------------------------- parity (port of .github/scripts/lilium-parity.py)
const PARITY_PAGE = 2000;
const PARITY_SQL = [
    'tables' => "SELECT TABLE_NAME AS t, ENGINE AS engine, TABLE_COLLATION AS coll FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
    'columns' => "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt, EXTRA AS extra, COLLATION_NAME AS coll FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION",
    'indexes' => "SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS nu, SEQ_IN_INDEX AS seq, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
];
const PARITY_LEDGER_SQL = 'SELECT file, sha256 FROM schema_migration_log WHERE ok = 1 ORDER BY id';
const PARITY_SPECS = ['tables' => [['t'], ['engine', 'coll']], 'columns' => [['t', 'c'], ['type', 'nullable', 'dflt', 'extra', 'coll']],
                      'indexes' => [['t', 'i', 'seq'], ['nu', 'c']]];

function parity_query(array $site, string $env, string $sql): array {
    $rows = []; $off = 0;
    while (true) {
        [$code, $j] = dh_post($site, $env, ['action' => 'query', 'sql' => "$sql LIMIT " . PARITY_PAGE . " OFFSET $off", 'files' => []]);
        if (empty($j['ok'])) throw new RuntimeException('query: ' . ($j['error'] ?? "HTTP $code"));
        $rows = array_merge($rows, $j['rows']);
        if (count($j['rows']) < PARITY_PAGE) return $rows;
        $off += PARITY_PAGE;
    }
}

function parity_fetch(string $name, array $site, string $env): array {
    $host = parse_url(dh_endpoint($site, $env), PHP_URL_HOST);
    $snap = ['domain' => substr($host, strlen($env) + 1), 'env' => $env, 'files' => [], 'errors' => []];
    $start = 0;
    while (true) {
        [$code, $j] = dh_post($site, $env, ['action' => 'manifest', 'start' => $start, 'files' => []]);
        if (empty($j['ok'])) { $snap['errors'][] = 'manifest: ' . ($j['error'] ?? "HTTP $code"); break; }
        foreach ($j['lines'] as $l) {
            [$h, $p] = explode('  ', $l, 2);
            $q = explode('/public_html/', $p, 2);
            $snap['files'][end($q)] = $h;
        }
        if (!empty($j['done']) || ($j['count'] ?? 0) == 0) break;
        $start = (int) $j['next'];
    }
    foreach (PARITY_SQL as $k => $sql) {
        try { $snap[$k] = parity_query($site, $env, $sql); } catch (Throwable $e) { $snap['errors'][] = "$k: " . $e->getMessage(); }
    }
    try { $snap['ledger'] = parity_query($site, $env, PARITY_LEDGER_SQL); }
    catch (Throwable $e) { $snap['ledger'] = null; $snap['ledger_note'] = substr($e->getMessage(), 0, 200); }
    return $snap;
}

function parity_keyed(?array $rows, array $key, array $val): array {
    $out = [];
    foreach ($rows ?? [] as $r) {
        $k = array_map(fn($x) => $r[$x] ?? null, $key);
        if (($k[0] ?? null) === 'schema_migration_log') continue;
        $out[json_encode($k)] = array_map(fn($x) => $r[$x] ?? null, $val);
    }
    return $out;
}

function parity_compare(array $a, array $b, array $expected): array {
    $out = ['files' => [], 'tables' => [], 'columns' => [], 'indexes' => [], 'ledger' => [], 'errors' => []];
    foreach ([$a, $b] as $s) foreach ($s['errors'] ?? [] as $e) $out['errors'][] = $s['domain'] . ': ' . $e;
    $fa = $a['files'] ?? []; $fb = $b['files'] ?? [];
    $all = array_unique(array_merge(array_keys($fa), array_keys($fb))); sort($all, SORT_STRING);
    foreach ($all as $p) {
        $p = (string) $p;
        $ok = ($fa[$p] ?? null) === ($fb[$p] ?? null);
        foreach ($expected as $pat) if (fnmatch($pat, $p)) $ok = true;
        if ($ok) continue;
        $out['files'][] = ['path' => $p, 'state' => !isset($fb[$p]) ? 'only-in-A' : (!isset($fa[$p]) ? 'only-in-B' : 'differs')];
    }
    foreach (PARITY_SPECS as $k => [$key, $val]) {
        $ka = parity_keyed($a[$k] ?? null, $key, $val); $kb = parity_keyed($b[$k] ?? null, $key, $val);
        $keys = array_unique(array_merge(array_keys($ka), array_keys($kb)));
        usort($keys, fn($x, $y) => strcmp(implode("\0", array_map('strval', json_decode($x, true))), implode("\0", array_map('strval', json_decode($y, true)))));
        foreach ($keys as $kk) {
            if (($ka[$kk] ?? null) === ($kb[$kk] ?? null)) continue;
            $out[$k][] = ['key' => implode('.', array_map('strval', json_decode($kk, true))),
                          'state' => !isset($kb[$kk]) ? 'only-in-A' : (!isset($ka[$kk]) ? 'only-in-B' : 'differs'),
                          'A' => $ka[$kk] ?? null, 'B' => $kb[$kk] ?? null];
        }
    }
    $la = $a['ledger'] === null ? null : array_column($a['ledger'], 'sha256', 'file');
    $lb = $b['ledger'] === null ? null : array_column($b['ledger'], 'sha256', 'file');
    if ($la !== null && $lb !== null) {
        $all = array_unique(array_merge(array_keys($la), array_keys($lb))); sort($all, SORT_STRING);
        foreach ($all as $f) if (($la[$f] ?? null) !== ($lb[$f] ?? null))
            $out['ledger'][] = ['file' => $f, 'state' => !isset($lb[$f]) ? 'only-in-A' : (!isset($la[$f]) ? 'only-in-B' : 'different-version')];
    } elseif (($la === null) !== ($lb === null)) {
        $out['ledger'][] = ['file' => '(schema_migration_log)', 'state' => $lb === null ? 'only-in-A' : 'only-in-B'];
    }
    return $out;
}

function dh_parity(string $name, array $site, string $env, string $id): array {
    $prodName = $site['prod'] ?? '';
    $prod = dh_sites()[$prodName] ?? null;
    if (!$prod || trim((string) (dh_secrets()[$prod['secret']] ?? '')) === '')
        return ['action' => 'parity', 'env' => $env, 'ok' => false, 'error' => 'not configured: ' . ($prod['secret'] ?? 'prod site') . ' missing in secrets.php'];
    [$c, $raw] = dh_gh($site['repo'], 'GET', '/contents/.github/parity-expected-diff.txt?ref=main', null, 'application/vnd.github.raw');
    $expected = [];
    if ($c === 200) foreach (preg_split('/\r?\n/', (string) $raw) as $ln) { $ln = trim(explode('#', $ln, 2)[0]); if ($ln !== '') $expected[] = $ln; }
    $a = parity_fetch($name, $site, $env); $b = parity_fetch($prodName, $prod, $env);
    $res = parity_compare($a, $b, $expected);
    $n = array_sum(array_map('count', $res));
    $lines = ["## یکسانیِ دو سایت — `$env`", '', '- **آ:** `' . $a['domain'] . '` · **ب:** `' . $b['domain'] . '`',
              vsprintf('- فایل: %d تفاوت · جدول: %d · ستون: %d · ایندکس: %d · مایگریشن: %d · خطا: %d',
                       array_map(fn($k) => count($res[$k]), ['files', 'tables', 'columns', 'indexes', 'ledger', 'errors'])), ''];
    foreach (['errors', 'files', 'tables', 'columns', 'indexes', 'ledger'] as $k)
        foreach (array_slice($res[$k], 0, 200) as $item) $lines[] = "- `$k` " . json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $lines[] = ''; $lines[] = $n === 0 ? '✅ دو سایت یکی‌اند.' : "⛔ دو سایت یکی نیستند — $n مورد.";
    $file = "deploy-results/parity-$env.md";
    dh_put_file($site, $file, implode("\n", $lines) . "\n", "parity: $env (sites-host: $id)");
    return ['action' => 'parity', 'env' => $env, 'ok' => $n === 0, 'differences' => $n, 'file' => $file];
}
