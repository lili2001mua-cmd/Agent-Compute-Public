<?php
// sites.hohedoc.ir — one door for every site the team builds (most are hosted inside Iran, which this
// Claude environment cannot reach). Zero GitHub Actions minutes, however often the sites change.
// Grew out of a PHP port of stylistcrm/Sys.scripts/apps-script/lilium-deploy.gs (the Google channel that
// stopped silently after 1405-07-20): for each site in sites.php, read <queue>/<id>.json on main, fetch each
// file at the commit that wrote the request, sign the body with HMAC-SHA256 exactly like lilium-call.py,
// POST it to the site's bridge (sync.php), and write the outcome to <results>/<id>.json on main.
//
// The user's approval stays in the chat: a session writes a queue file only after
// "تأیید انتقال به هاست" (host_transfer_gate.py in Agent-Project-Backup). This host never decides.
//
// Secrets live in ../secrets.php (outside public_html, chmod 600); ../secrets-extra.php is merged on top.
// Keys: GITHUB_TOKEN (fine-grained, Contents: Read and write) and one bridge secret per site, named in sites.php.
const DH_ROOT = __DIR__ . '/..';
const DH_VALID_ACTION = ['deploy' => 1, 'delete' => 1];
// Overridable only from the process environment, for the local mock test (sites-host/selftest.sh).
define('DH_GH_API', getenv('DH_GH_API') ?: 'https://api.github.com');

function dh_sites(): array {
    static $s = null;
    if ($s === null) $s = require __DIR__ . '/sites.php';
    return $s;
}

function dh_secrets(): array {
    static $s = null;
    if ($s === null) {
        $s = [];
        foreach ([DH_ROOT . '/secrets.php', DH_ROOT . '/secrets-extra.php'] as $p) {
            if (is_file($p)) {
                $part = include $p;
                if (is_array($part)) $s = array_merge($s, array_filter($part, fn($v) => $v !== '' && $v !== null));
            }
        }
    }
    return $s;
}

function dh_secret(string $key): string {
    $v = trim((string) (dh_secrets()[$key] ?? ''));
    if ($v === '') throw new RuntimeException('missing secret ' . $key);
    return $v;
}

function dh_var(string $name): string {
    $dir = DH_ROOT . '/var';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir . '/' . $name;
}

// Never logs a secret, a body or a signature: only ids, actions, envs and status codes.
function dh_log(string $line): void {
    file_put_contents(dh_var('deploy-queue.log'), gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function dh_state(): array {
    return @json_decode((string) @file_get_contents(dh_var('state.json')), true) ?: [];
}

function dh_state_set(string $k, $v): void {
    $s = dh_state();
    $s[$k] = $v;
    file_put_contents(dh_var('state.json'), json_encode($s, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function dh_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'Lilium-DeployHost'];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [0, null, 'network: ' . $err];
    return [$code, (string) $raw, ''];
}

// ---------------------------------------------------------------- GitHub (contents API, one repo)
function dh_owner(): string {
    $c = dh_state()['owner'] ?? '';
    if ($c !== '') return $c;
    [$code, $raw] = dh_http('GET', DH_GH_API . '/user', dh_gh_headers());
    $login = $code === 200 ? (json_decode($raw, true)['login'] ?? '') : '';
    // A silent empty queue is how the Google channel died unnoticed — fail loudly instead.
    if ($login === '') throw new RuntimeException('cannot derive owner: HTTP ' . $code);
    dh_state_set('owner', $login);
    return $login;
}

function dh_gh_headers(string $accept = 'application/vnd.github+json'): array {
    return ['Authorization: Bearer ' . dh_secret('GITHUB_TOKEN'), 'Accept: ' . $accept,
            'X-GitHub-Api-Version: 2022-11-28'];
}

function dh_gh(string $repo, string $method, string $path, ?array $payload = null, string $accept = 'application/vnd.github+json'): array {
    $h = dh_gh_headers($accept);
    $body = null;
    if ($payload !== null) { $h[] = 'Content-Type: application/json'; $body = json_encode($payload, JSON_UNESCAPED_SLASHES); }
    [$code, $raw, $err] = dh_http($method, DH_GH_API . '/repos/' . dh_owner() . '/' . $repo . $path, $h, $body);
    return [$code, $accept === 'application/vnd.github.raw' ? $raw : json_decode((string) $raw, true), $err];
}

function dh_enc_path(string $p): string {
    return implode('/', array_map('rawurlencode', explode('/', $p)));
}

// Queue entries of one site. Any non-200 throws: an unreadable queue must never look like an empty one.
function dh_list_queue(array $site): array {
    [$code, $j] = dh_gh($site['repo'], 'GET', '/contents/' . $site['queue'] . '?ref=main');
    if ($code === 404) return [];                       // folder absent = really empty
    if ($code !== 200 || !is_array($j)) throw new RuntimeException($site['repo'] . ' queue unreadable: HTTP ' . $code);
    $out = [];
    foreach ($j as $f) {
        if (($f['type'] ?? '') !== 'file' || !preg_match('/^([A-Za-z0-9._-]+)\.json$/', $f['name'] ?? '', $m)) continue;
        $out[] = ['id' => $m[1], 'path' => $f['path']];
    }
    return $out;
}

function dh_last_commit(array $site, string $path): string {
    [$code, $j] = dh_gh($site['repo'], 'GET', '/commits?path=' . rawurlencode($path) . '&sha=main&per_page=1');
    if ($code !== 200) throw new RuntimeException('commit lookup failed: HTTP ' . $code);
    return (string) ($j[0]['sha'] ?? '');
}

function dh_result_exists(array $site, string $id): bool {
    [$code] = dh_gh($site['repo'], 'GET', '/contents/' . $site['results'] . '/' . rawurlencode($id) . '.json?ref=main');
    if ($code === 200) return true;
    if ($code === 404) return false;
    throw new RuntimeException('result lookup failed: HTTP ' . $code);
}

function dh_read_request(array $site, string $path, string $commit): ?array {
    [$code, $raw] = dh_gh($site['repo'], 'GET', '/contents/' . dh_enc_path($path) . '?ref=' . $commit, null, 'application/vnd.github.raw');
    if ($code !== 200) return null;
    $req = json_decode((string) $raw, true);
    $groups = is_array($req['groups'] ?? null) ? $req['groups'] : [];
    if (!$groups) return null;
    foreach ($groups as $g) {
        if (!isset(DH_VALID_ACTION[$g['action'] ?? '']) || !isset($site['envs'][$g['env'] ?? ''])
            || !is_array($g['files'] ?? null) || !$g['files']) return null;
    }
    return $req;
}

// ---------------------------------------------------------------- site bridge (sync.php)
function dh_endpoint(array $site, string $env): string {
    if (getenv('DH_SITE_BASE')) return getenv('DH_SITE_BASE') . '/' . $env . '/sync.php';
    return $site['envs'][$env];
}

// Signature exactly like lilium-call.py: X-Signature: sha256=<hmac hex over the raw body>.
function dh_post(array $site, string $env, array $payload): array {
    $payload['ts'] = time();
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sig = hash_hmac('sha256', $body, dh_secret($site['secret']));
    [$code, $raw, $err] = dh_http('POST', dh_endpoint($site, $env),
        ['Content-Type: application/json', 'X-Signature: sha256=' . $sig], $body, 120);
    return [$code, $raw === null ? null : json_decode($raw, true), $err];
}

// Read-only reachability check of every env of every site; stored for status.php (codes and booleans only).
function dh_ping(): array {
    $r = [];
    foreach (dh_sites() as $name => $site) {
        foreach (array_keys($site['envs']) as $env) {
            try { [$code, $j, $err] = dh_post($site, $env, ['action' => 'ping', 'ref' => 'main', 'files' => []]); }
            catch (Throwable $e) { [$code, $j, $err] = [0, null, $e->getMessage()]; }
            $r["$name/$env"] = ['http' => $code, 'ok' => (bool) ($j['ok'] ?? false)] + ($err ? ['error' => substr($err, 0, 120)] : []);
        }
    }
    dh_state_set('ping', ['at' => gmdate('c'), 'sites' => $r]);
    return $r;
}

// ---------------------------------------------------------------- one queue request
function dh_run(string $name, array $site, array $q, string $commit): array {
    $parts = []; $results = []; $deleted = [];
    $req = dh_read_request($site, $q['path'], $commit);
    if (!$req) {
        $parts[] = ['ok' => false, 'error' => 'bad-request'];
    } else {
        $groups = $req['groups'];
        usort($groups, fn($x, $y) => ($x['action'] === 'deploy' ? 0 : 1) <=> ($y['action'] === 'deploy' ? 0 : 1));
        foreach ($groups as $g) {
            $payload = ['action' => $g['action'], 'ref' => $req['ref'] ?? 'main', 'files' => $g['files']];
            if ($g['action'] === 'deploy') {
                $files = []; $missing = [];
                foreach ($g['files'] as $rp) {
                    [$code, $raw] = dh_gh($site['repo'], 'GET', '/contents/' . dh_enc_path($rp) . '?ref=' . $commit, null, 'application/vnd.github.raw');
                    if ($code !== 200 || $raw === null) { $missing[] = $rp; continue; }
                    $files[] = ['repo' => $rp, 'b64' => base64_encode($raw), 'sha256' => hash('sha256', $raw)];
                }
                if ($missing) { $parts[] = ['action' => 'deploy', 'env' => $g['env'], 'ok' => false, 'error' => 'not-in-repo', 'files' => $missing]; continue; }
                $payload['files'] = $files;
            }
            [$code, $j, $err] = dh_post($site, $g['env'], $payload);
            $ok = $code === 200 && !empty($j['ok']);
            foreach ((is_array($j['results'] ?? null) ? $j['results'] : []) as $row) {
                $row['env'] = $g['env'];
                if ($g['action'] === 'deploy') $results[] = $row; else $deleted[] = $row;
            }
            $parts[] = ['action' => $g['action'], 'env' => $g['env'], 'files' => $g['files'], 'ok' => $ok, 'http' => $code]
                       + ($err ? ['error' => substr($err, 0, 120)] : []);
            dh_log("$name/" . $q['id'] . ' ' . $g['action'] . ' ' . $g['env'] . ' -> HTTP ' . $code);
        }
    }
    $out = ['ok' => (bool) array_product(array_map(fn($p) => $p['ok'] ? 1 : 0, $parts)), 'channel' => 'sites-host',
            'id' => $q['id'], 'request_commit' => $commit, 'action' => $results ? 'deploy' : 'delete',
            'results' => $results, 'deleted' => $deleted, 'parts' => $parts, 'at' => gmdate('c')];
    $path = $site['results'] . '/' . $q['id'] . '.json';
    [$pc, $prev] = dh_gh($site['repo'], 'GET', '/contents/' . dh_enc_path($path) . '?ref=main');
    $put = ['message' => 'deploy-result: ' . $out['action'] . ' (sites-host: ' . $q['id'] . ')',
            'content' => base64_encode(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'branch' => 'main'];
    if ($pc === 200 && !empty($prev['sha'])) $put['sha'] = $prev['sha'];
    [$wc] = dh_gh($site['repo'], 'PUT', '/contents/' . dh_enc_path($path), $put);
    if ($wc !== 200 && $wc !== 201) throw new RuntimeException('result write failed: HTTP ' . $wc);
    return $out;
}

// ---------------------------------------------------------------- one poll over every site
// Each request runs once. Requests that already have a result file (run by the Google channel or by
// lilium-deploy.yml) are only marked done, never re-run. One broken site does not stop the others.
function dh_poll(): int {
    $ran = 0; $errors = [];
    foreach (dh_sites() as $name => $site) {
        try {
            $done = dh_state()['done'] ?? [];
            foreach (dh_list_queue($site) as $q) {
                $key = "$name/" . $q['id'];
                if (isset($done[$key])) continue;
                if (dh_result_exists($site, $q['id'])) { $done[$key] = 'existing'; dh_state_set('done', $done); continue; }
                $commit = dh_last_commit($site, $q['path']);
                $done[$key] = gmdate('c');      // one-shot, even if the run fails (same as the Google channel)
                dh_state_set('done', $done);
                $out = dh_run($name, $site, $q, $commit);
                dh_state_set('last_run', ['id' => $key, 'ok' => $out['ok'], 'at' => $out['at']]);
                $ran++;
            }
        } catch (Throwable $e) {
            $errors[] = $name . ': ' . $e->getMessage();
        }
    }
    if ($errors) throw new RuntimeException(implode(' | ', $errors));
    return $ran;
}
