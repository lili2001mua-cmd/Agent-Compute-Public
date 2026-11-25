<?php
// Sealed direct HTTPS door on sites.hohedoc.ir for authorized holding AI employees (Gemini, Claude, Codex, Grok, ...).
// Uses the exact same chain-bus/v1 cryptographic envelope as ops-host/files/app/bus.php and Agent-Project-Backup
// Sys.scripts/lib/door_seal.py (key = Agent-Project-Backup/Sys.registry/tg-host-read-key.secret, fetched via this
// host's existing GITHUB_TOKEN and cached chmod 0600 in var/door-key.json).
//
// Zero new secrets on this host, zero secrets in git, zero GitHub Actions minutes.
// Entry: public_html/door.php · GET = readiness probe (booleans only) · POST = sealed request (kind "sites-door"),
// answer sealed back with kind "sites-door-res" and the same request id.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/ops.php';
require_once __DIR__ . '/da_provision.php';
require_once __DIR__ . '/de_mirror_sync.php';

const DOOR_KEY_REPO = 'Agent-Project-Backup';
const DOOR_KEY_PATH = 'Sys.registry/tg-host-read-key.secret';
const DOOR_KIND_REQ = 'sites-door';
const DOOR_KIND_RES = 'sites-door-res';
const DOOR_MAX_BODY = 6 * 1024 * 1024;   // 6 MiB — fits base64-encoded files up to sync.php's 3 MiB limit
const DOOR_RUN_MAX  = 512 * 1024;        // 512 KiB cap for direct HTTP GET (`run`)
const DOOR_VALID_ACTIONS = [
    'ping' => 1, 'snapshot' => 1, 'manifest' => 1, 'query' => 1, 'run' => 1,
    'deploy' => 1, 'delete' => 1, 'migrate' => 1,
    'da_status' => 1, 'da_job' => 1, 'da_result' => 1,
    'de_sync_status' => 1,
];
const DOOR_WRITE_ACTIONS = ['deploy' => 1, 'delete' => 1, 'migrate' => 1, 'da_job' => 1];

function door_be64(int $n): string {
    return pack('J', $n);
}

/** Fetch and derive enc/mac keys from the private holding repo's door key (cached 1h, chmod 0600). */
function door_keys(): ?array {
    $envKey = trim((string) getenv('DH_DOOR_KEY'));   // selftest override only
    if ($envKey !== '') {
        $door = $envKey;
    } else {
        $cache = dh_var('door-key.json');
        $c = @json_decode((string) @file_get_contents($cache), true);
        if (is_array($c) && ($c['at'] ?? 0) > time() - 3600 && is_string($c['k'] ?? null)) {
            $door = trim($c['k']);
        } else {
            try {
                [$code, $raw] = dh_gh(DOOR_KEY_REPO, 'GET', '/contents/' . dh_enc_path(DOOR_KEY_PATH) . '?ref=main',
                    null, 'application/vnd.github.raw');
            } catch (Throwable $e) {
                return null;
            }
            if ($code !== 200 || !is_string($raw)) return null;
            $door = trim($raw);
            if (strlen($door) < 16) return null;
            file_put_contents($cache, json_encode(['k' => $door, 'at' => time()]), LOCK_EX);
            @chmod($cache, 0600);
        }
    }
    if (strlen($door) < 16) return null;
    $k = hash_hmac('sha256', 'chain-bus/v1', $door, true);
    return [
        'enc' => hash_hmac('sha256', 'enc', $k, true),
        'mac' => hash_hmac('sha256', 'mac', $k, true),
    ];
}

function door_xor(string $data, string $kenc, string $nonce): string {
    $out = '';
    $n = strlen($data);
    for ($i = 0, $b = 0; $i < $n; $i += 32, $b++) {
        $ks = hash_hmac('sha256', $nonce . door_be64($b), $kenc, true);
        $out .= substr($data, $i, 32) ^ substr($ks, 0, min(32, $n - $i));
    }
    return $out;
}

function door_seal(array $keys, string $kind, string $id, string $pt, int $exp): array {
    $nonce = random_bytes(16);
    $ct = door_xor($pt, $keys['enc'], $nonce);
    $tag = hash_hmac('sha256', $kind . '|' . $id . '|' . bin2hex($nonce) . '|' . $exp . '|' . hash('sha256', $ct), $keys['mac']);
    return [
        'v' => 1, 'kind' => $kind, 'id' => $id, 'nonce' => bin2hex($nonce),
        'exp' => $exp, 'ct' => base64_encode($ct), 'tag' => $tag,
    ];
}

function door_open(array $keys, array $env, string $kind): ?string {
    if (($env['v'] ?? 0) !== 1 || ($env['kind'] ?? '') !== $kind) return null;
    $id = (string) ($env['id'] ?? '');
    $nh = (string) ($env['nonce'] ?? '');
    $exp = (int) ($env['exp'] ?? 0);
    if (!preg_match('/^[0-9a-f]{32}$/', $id) || !preg_match('/^[0-9a-f]{32}$/', $nh)) return null;
    $ct = base64_decode((string) ($env['ct'] ?? ''), true);
    if ($ct === false) return null;
    $want = hash_hmac('sha256', $kind . '|' . $id . '|' . $nh . '|' . $exp . '|' . hash('sha256', $ct), $keys['mac']);
    if (!hash_equals($want, (string) ($env['tag'] ?? ''))) return null;
    return door_xor($ct, $keys['enc'], hex2bin($nh));
}

function door_log(string $line): void {
    file_put_contents(dh_var('door.log'), gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Validate repo-relative path for deploy/delete/snapshot: no traversal, no deploy/, no secret/upload paths. */
function door_path_ok(string $env, string $rp): bool {
    if (!preg_match('#^([a-z]+)/public_html/([A-Za-z0-9_./\-]+)$#', $rp, $m)) return false;
    $baseEnv = preg_replace('/-de$/', '', $env);
    if ($m[1] !== $baseEnv) return false;
    foreach (explode('/', $m[2]) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') return false;
    }
    if ($m[2] === 'deploy' || strpos($m[2], 'deploy/') === 0) return false;
    if (dh_pull_refused($rp)) return false;
    return true;
}

/** Execute one validated, decrypted request against the site's sync.php (or env HTTP host for `run`). */
function door_dispatch(array $req, string $id): array {
    $siteName = (string) ($req['site'] ?? 'stylistcrm');
    $env = (string) ($req['env'] ?? '');
    $action = (string) ($req['action'] ?? '');
    $actor = preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($req['actor'] ?? 'unknown')) ?: 'unknown';

    if (!isset(DOOR_VALID_ACTIONS[$action])) {
        return [400, ['ok' => false, 'error' => 'action not allowed']];
    }
    if (isset(DOOR_WRITE_ACTIONS[$action])) {
        $quote = trim((string) ($req['approval_quote'] ?? ''));
        if ($quote === '') {
            return [400, ['ok' => false, 'error' => 'approval_quote required for write actions']];
        }
    }

    if ($action === 'da_status') {
        door_log("$id actor=$actor da_status");
        return [200, da_status_snapshot()];
    }
    if ($action === 'da_job') {
        [$c, $r] = da_queue_job($req, $id, $actor);
        door_log("$id actor=$actor da_job op=" . ((string) ($req['op'] ?? '')) . " code=$c");
        return [$c, $r];
    }
    if ($action === 'da_result') {
        $jobId = (string) ($req['job_id'] ?? '');
        [$c, $r] = da_read_result($jobId);
        door_log("$id actor=$actor da_result job=$jobId code=$c");
        return [$c, $r];
    }
    if ($action === 'de_sync_status') {
        door_log("$id actor=$actor de_sync_status");
        return [200, de_mirror_status_snapshot()];
    }

    $sites = dh_sites();
    if (!isset($sites[$siteName]) || (($sites[$siteName]['queue'] ?? '') === '' && empty($sites[$siteName]['door_enabled']))) {
        return [400, ['ok' => false, 'error' => 'site not allowed']];
    }
    $site = $sites[$siteName];
    if (!isset($site['envs'][$env])) {
        return [400, ['ok' => false, 'error' => 'env not allowed']];
    }

    if ($action === 'ping') {
        [$code, $j, $err] = dh_post($site, $env, ['action' => 'ping', 'ref' => 'main', 'files' => []]);
        door_log("$id actor=$actor $siteName/$env ping http=$code ok=" . (!empty($j['ok']) ? '1' : '0'));
        return [200, ['ok' => $code === 200 && !empty($j['ok']), 'http' => $code, 'bridge' => $j] + ($err ? ['error' => $err] : [])];
    }

    if ($action === 'manifest') {
        $start = max(0, (int) ($req['start'] ?? 0));
        $limit = max(1, min(400, (int) ($req['limit'] ?? 400)));
        [$code, $j, $err] = dh_post($site, $env, ['action' => 'manifest', 'ref' => 'main', 'start' => $start, 'limit' => $limit, 'files' => []]);
        door_log("$id actor=$actor $siteName/$env manifest start=$start http=$code ok=" . (!empty($j['ok']) ? '1' : '0'));
        return [200, ['ok' => $code === 200 && !empty($j['ok']), 'http' => $code, 'bridge' => $j] + ($err ? ['error' => $err] : [])];
    }

    if ($action === 'query') {
        $sql = trim((string) ($req['sql'] ?? ''));
        if ($sql === '') return [400, ['ok' => false, 'error' => 'sql required']];
        [$code, $j, $err] = dh_post($site, $env, ['action' => 'query', 'ref' => 'main', 'sql' => $sql, 'files' => []]);
        door_log("$id actor=$actor $siteName/$env query http=$code ok=" . (!empty($j['ok']) ? '1' : '0'));
        return [200, ['ok' => $code === 200 && !empty($j['ok']), 'http' => $code, 'bridge' => $j] + ($err ? ['error' => $err] : [])];
    }

    if ($action === 'snapshot') {
        $files = is_array($req['files'] ?? null) ? array_values($req['files']) : [];
        if (!$files || count($files) > 50) return [400, ['ok' => false, 'error' => 'files must be 1..50 paths']];
        foreach ($files as $f) {
            if (!is_string($f) || !door_path_ok($env, $f)) {
                return [403, ['ok' => false, 'error' => 'path refused: ' . (string) $f]];
            }
        }
        [$code, $j, $err] = dh_post($site, $env, ['action' => 'snapshot', 'ref' => 'main', 'files' => $files]);
        door_log("$id actor=$actor $siteName/$env snapshot n=" . count($files) . " http=$code ok=" . (!empty($j['ok']) ? '1' : '0'));
        return [200, ['ok' => $code === 200 && !empty($j['ok']), 'http' => $code, 'bridge' => $j] + ($err ? ['error' => $err] : [])];
    }

    if ($action === 'deploy') {
        $files = is_array($req['files'] ?? null) ? array_values($req['files']) : [];
        if (!$files || count($files) > 200) return [400, ['ok' => false, 'error' => 'files must be 1..200 items']];
        $clean = [];
        foreach ($files as $f) {
            if (!is_array($f)) return [400, ['ok' => false, 'error' => 'each deploy item must be an object']];
            $rp = (string) ($f['repo'] ?? '');
            $b64 = (string) ($f['b64'] ?? '');
            $sha = strtolower((string) ($f['sha256'] ?? ''));
            if (!door_path_ok($env, $rp)) return [403, ['ok' => false, 'error' => 'path refused: ' . $rp]];
            $raw = base64_decode($b64, true);
            if ($raw === false || !preg_match('/^[0-9a-f]{64}$/', $sha) || !hash_equals($sha, hash('sha256', $raw))) {
                return [400, ['ok' => false, 'error' => 'bad content/sha256 for ' . $rp]];
            }
            $clean[] = ['repo' => $rp, 'b64' => $b64, 'sha256' => $sha];
        }
        [$code, $j, $err] = dh_post($site, $env, ['action' => 'deploy', 'ref' => 'main', 'files' => $clean]);
        door_log("$id actor=$actor $siteName/$env deploy n=" . count($clean) . " http=$code ok=" . (!empty($j['ok']) ? '1' : '0'));
        return [200, ['ok' => $code === 200 && !empty($j['ok']), 'http' => $code, 'bridge' => $j] + ($err ? ['error' => $err] : [])];
    }

    if ($action === 'delete') {
        $files = is_array($req['files'] ?? null) ? array_values($req['files']) : [];
        if (!$files || count($files) > 200) return [400, ['ok' => false, 'error' => 'files must be 1..200 paths']];
        foreach ($files as $f) {
            if (!is_string($f) || !door_path_ok($env, $f)) {
                return [403, ['ok' => false, 'error' => 'path refused: ' . (string) $f]];
            }
        }
        [$code, $j, $err] = dh_post($site, $env, ['action' => 'delete', 'ref' => 'main', 'files' => $files]);
        door_log("$id actor=$actor $siteName/$env delete n=" . count($files) . " http=$code ok=" . (!empty($j['ok']) ? '1' : '0'));
        return [200, ['ok' => $code === 200 && !empty($j['ok']), 'http' => $code, 'bridge' => $j] + ($err ? ['error' => $err] : [])];
    }

    if ($action === 'migrate') {
        $mig = (string) ($req['file'] ?? '');
        if (!preg_match('#^migrations/[A-Za-z0-9_\-.]+\.php$#', $mig) || strpos($mig, '..') !== false) {
            return [400, ['ok' => false, 'error' => 'bad migration path']];
        }
        [$code, $j, $err] = dh_post($site, $env, ['action' => 'migrate', 'ref' => 'main', 'file' => $mig, 'files' => []]);
        door_log("$id actor=$actor $siteName/$env migrate file=$mig http=$code ok=" . (!empty($j['ok']) ? '1' : '0'));
        return [200, ['ok' => $code === 200 && !empty($j['ok']), 'http' => $code, 'bridge' => $j] + ($err ? ['error' => $err] : [])];
    }

    if ($action === 'run') {
        $path = (string) ($req['path'] ?? '');
        if (!dh_run_path_ok($path)) return [400, ['ok' => false, 'error' => 'bad run path']];
        $u = parse_url(dh_endpoint($site, $env));
        $url = ($u['scheme'] ?? 'https') . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '') . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_USERAGENT => 'Lilium-DeployHost',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $body = $body === false ? '' : substr((string) $body, 0, DOOR_RUN_MAX);
        door_log("$id actor=$actor $siteName/$env run http=$code");
        return [200, ['ok' => $code === 200, 'http' => $code, 'body' => $body] + ($err ? ['error' => $err] : [])];
    }

    return [400, ['ok' => false, 'error' => 'unhandled action']];
}

/** One HTTP call to public_html/door.php. GET = readiness probe · POST = sealed request. */
function door_handle(string $method, string $body): array {
    $keys = door_keys();
    if ($method === 'GET') {
        $readySites = [];
        foreach (dh_sites() as $name => $site) {
            if (($site['queue'] ?? '') !== '' && trim((string) (dh_secrets()[$site['secret']] ?? '')) !== '') {
                $readySites[] = $name;
            }
        }
        return [200, ['door' => 'ready', 'key' => $keys !== null, 'sites' => $readySites]];
    }
    if ($method !== 'POST') return [405, ['error' => 'method']];

    $fF = dh_var('door-fail.json');
    $f = @json_decode((string) @file_get_contents($fF), true) ?: ['n' => 0, 't' => 0];
    if (time() - ($f['t'] ?? 0) > 600) $f = ['n' => 0, 't' => time()];
    if (($f['n'] ?? 0) >= 20) return [429, ['error' => 'closed for a while']];
    if ($keys === null) return [503, ['error' => 'host has no door key']];
    if (strlen($body) > DOOR_MAX_BODY) return [413, ['error' => 'body too large']];

    $env = json_decode($body, true);
    $pt = is_array($env) ? door_open($keys, $env, DOOR_KIND_REQ) : null;
    $now = time();
    $id = (string) ($env['id'] ?? '');
    $eexp = (int) ($env['exp'] ?? 0);
    $seenF = dh_var('door-seen.json');
    $seen = @json_decode((string) @file_get_contents($seenF), true) ?: [];
    foreach ($seen as $k => $t) if ($t < $now - 3600) unset($seen[$k]);

    if ($pt === null || $eexp < $now || $eexp > $now + 900 || isset($seen[$id])) {
        $f['n'] = ($f['n'] ?? 0) + 1;
        file_put_contents($fF, json_encode($f), LOCK_EX);
        return [403, ['error' => 'refused']];
    }
    $seen[$id] = $now;
    file_put_contents($seenF, json_encode($seen), LOCK_EX);

    $req = json_decode($pt, true);
    if (!is_array($req)) return [400, ['error' => 'bad json payload']];

    [$code, $resp] = door_dispatch($req, $id);
    $sealed = door_seal($keys, DOOR_KIND_RES, $id, json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), time() + 600);
    return [$code, $sealed];
}
