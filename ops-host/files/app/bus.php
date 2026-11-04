<?php
// chain-bus relay — the holding's HTTP door that needs nothing from a Claude account (user's rule 1405-08-13:
// «هیچ فرایندی که وابسته به اکانتِ کلاد باشد قابل قبول نیست»). A Claude cloud session whose network allows only
// GitHub writes an encrypted request file to the private repo's `chain-bus` branch; this host reads it, performs
// the HTTP call against an allowlist, and commits the encrypted answer back to the same branch.
// Plan: Agent-Project-Backup/Memory-Workflow/chain-github-mailbox/MANIFEST.md · client: Sys.scripts/lib/chain_bus.py
//
// Envelope (same bytes on both sides; test vector in chain_bus.py --selftest):
//   K    = HMAC-SHA256(door_key, "chain-bus/v1")            door_key = Sys.registry/tg-host-read-key.secret (private repo)
//   Kenc = HMAC(K, "enc") · Kmac = HMAC(K, "mac")
//   ct   = pt XOR (HMAC(Kenc, nonce || be64(0)) || HMAC(Kenc, nonce || be64(1)) || …)
//   tag  = HMAC(Kmac, kind || "|" || id || "|" || nonce_hex || "|" || exp || "|" || sha256(ct))
// Nothing secret is ever written in clear to the branch; the key never leaves the private repo and this host.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/stranded.php';   // ops_gh(), ops_github_token()

const BUS_REPO = 'lili2001mua-cmd/Agent-Project-Backup';
const BUS_BRANCH = 'chain-bus';
const BUS_KEY_PATH = 'Sys.registry/tg-host-read-key.secret';
const BUS_CHUNK = 8 * 1048576;            // raw bytes per answer chunk file
const BUS_MAX_BODY = 96 * 1048576;        // largest answer body we relay
const BUS_HOSTS = '/^(([a-z0-9-]+\.)*hohedoc\.ir|www\.googleapis\.com|oauth2\.googleapis\.com|drive\.google\.com|([a-z0-9-]+\.)*googleusercontent\.com|api\.telegram\.org)$/';

function bus_be64(int $n): string { return pack('J', $n); }

function bus_keys(): ?array {
    $cache = ops_var('bus-key.json');
    $c = @json_decode((string) @file_get_contents($cache), true);
    if (is_array($c) && ($c['at'] ?? 0) > time() - 3600) $door = (string) ($c['k'] ?? '');
    else {
        [$code, $raw] = ops_gh('GET', '/repos/' . BUS_REPO . '/contents/' . BUS_KEY_PATH . '?ref=main', null, 'application/vnd.github.raw');
        if ($code !== 200) return null;
        $door = trim((string) $raw);
        file_put_contents($cache, json_encode(['k' => $door, 'at' => time()]));
        @chmod($cache, 0600);
    }
    if (strlen($door) < 16) return null;
    $k = hash_hmac('sha256', 'chain-bus/v1', $door, true);
    return ['enc' => hash_hmac('sha256', 'enc', $k, true), 'mac' => hash_hmac('sha256', 'mac', $k, true)];
}

function bus_xor(string $data, string $kenc, string $nonce): string {
    $out = ''; $n = strlen($data);
    for ($i = 0, $b = 0; $i < $n; $i += 32, $b++) {
        $ks = hash_hmac('sha256', $nonce . bus_be64($b), $kenc, true);
        $out .= substr($data, $i, 32) ^ substr($ks, 0, min(32, $n - $i));
    }
    return $out;
}

function bus_seal(array $keys, string $kind, string $id, string $pt, int $exp): array {
    $nonce = random_bytes(16);
    $ct = bus_xor($pt, $keys['enc'], $nonce);
    $tag = hash_hmac('sha256', $kind . '|' . $id . '|' . bin2hex($nonce) . '|' . $exp . '|' . hash('sha256', $ct), $keys['mac']);
    return ['v' => 1, 'kind' => $kind, 'id' => $id, 'nonce' => bin2hex($nonce), 'exp' => $exp, 'ct' => base64_encode($ct), 'tag' => $tag];
}

function bus_open(array $keys, array $env, string $kind): ?string {
    if (($env['v'] ?? 0) !== 1 || ($env['kind'] ?? '') !== $kind) return null;
    $id = (string) ($env['id'] ?? ''); $nh = (string) ($env['nonce'] ?? ''); $exp = (int) ($env['exp'] ?? 0);
    if (!preg_match('/^[0-9a-f]{32}$/', $id) || !preg_match('/^[0-9a-f]{32}$/', $nh)) return null;
    $ct = base64_decode((string) ($env['ct'] ?? ''), true);
    if ($ct === false) return null;
    $want = hash_hmac('sha256', $kind . '|' . $id . '|' . $nh . '|' . $exp . '|' . hash('sha256', $ct), $keys['mac']);
    if (!hash_equals($want, (string) ($env['tag'] ?? ''))) return null;
    return bus_xor($ct, $keys['enc'], hex2bin($nh));
}

/** Perform one allowlisted HTTP call; the body goes to a temp file (never a whole video in memory twice). */
function bus_http(array $rq, string $tmp): array {
    $url = (string) ($rq['url'] ?? '');
    $p = parse_url($url);
    if (($p['scheme'] ?? '') !== 'https' || !preg_match(BUS_HOSTS, strtolower((string) ($p['host'] ?? '')))) return ['error' => 'host not allowed'];
    $method = strtoupper((string) ($rq['method'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) return ['error' => 'method not allowed'];
    $hdr = [];
    foreach ((array) ($rq['headers'] ?? []) as $k => $v) {
        if (preg_match('/^(host|content-length|connection|transfer-encoding|accept-encoding)$/i', (string) $k)) continue;
        $hdr[] = $k . ': ' . str_replace(["\r", "\n"], '', (string) $v);
    }
    $fh = fopen($tmp, 'wb'); $rh = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $hdr, CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_MAXFILESIZE => BUS_MAX_BODY,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$rh) {
            $t = trim($line);
            if (preg_match('#^HTTP/\S+\s+\d+#', $t)) $rh = [];
            elseif (strpos($t, ':') !== false) { [$k, $v] = explode(':', $t, 2); $rh[] = [trim($k), trim($v)]; }
            return strlen($line);
        }]);
    if (isset($rq['body_b64'])) curl_setopt($ch, CURLOPT_POSTFIELDS, (string) base64_decode((string) $rq['body_b64']));
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = $ok === false ? curl_error($ch) : '';
    curl_close($ch); fclose($fh);
    if ($ok === false) return ['error' => 'upstream: ' . $err];
    return ['status' => $code, 'headers' => $rh, 'size' => filesize($tmp)];
}

/** One poll: read the branch tree (ETag), answer every new request in a single commit. Returns answered count. */
function bus_poll(array $keys, array &$st): int {
    $etag = (string) ($st['etag'] ?? '');
    $ch = curl_init('https://api.github.com/repos/' . BUS_REPO . '/git/trees/' . BUS_BRANCH . '?recursive=1');
    $h = ['Authorization: Bearer ' . ops_github_token(), 'Accept: application/vnd.github+json', 'User-Agent: ops-hohedoc-bus'];
    if ($etag !== '') $h[] = 'If-None-Match: ' . $etag;
    $newEtag = '';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $h,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$newEtag) { if (stripos($l, 'etag:') === 0) $newEtag = trim(substr($l, 5)); return strlen($l); }]);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code === 304) return 0;
    if ($code !== 200) { $st['last_error'] = "tree $code"; return 0; }
    $st['etag'] = $newEtag;
    $tree = json_decode((string) $raw, true)['tree'] ?? [];
    $reqs = []; $old = [];
    foreach ($tree as $e) {
        if (($e['type'] ?? '') !== 'blob') continue;
        if (preg_match('#^req/([0-9a-f]{32})\.json$#', $e['path'], $m)) $reqs[$m[1]] = $e['sha'];
        elseif (preg_match('#^res/([0-9a-f]{32})\.#', $e['path'], $m)) $old[$e['path']] = $m[1];
    }
    if (!$reqs) return 0;
    $seenF = ops_var('bus-seen.json');
    $seen = @json_decode((string) @file_get_contents($seenF), true) ?: [];
    $now = time();
    foreach ($seen as $k => $t) if ($t < $now - 86400) unset($seen[$k]);
    $changes = [];   // path => content (string) | null (delete)
    $n = 0;
    foreach ($reqs as $id => $sha) {
        $changes['req/' . $id . '.json'] = null;
        if (isset($seen[$id])) continue;
        $seen[$id] = $now;
        [$c2, $blob] = ops_gh('GET', '/repos/' . BUS_REPO . '/git/blobs/' . $sha);
        $env = $c2 === 200 ? json_decode((string) base64_decode((string) ($blob['content'] ?? '')), true) : null;
        $pt = is_array($env) ? bus_open($keys, $env, 'req') : null;
        $rq = $pt !== null ? json_decode($pt, true) : null;
        $exp = $now + 3600;
        if (!is_array($rq) || ($env['exp'] ?? 0) < $now || ($env['id'] ?? '') !== $id) {
            ops_log("bus reject $id"); $st['rejected'] = ($st['rejected'] ?? 0) + 1;
            continue;   // forged, stale or replayed: dropped, never answered
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bus');
        $res = bus_http($rq, $tmp);
        $chunks = 0;
        if (isset($res['status'])) {
            $fh = fopen($tmp, 'rb');
            while (!feof($fh)) {
                $part = (string) fread($fh, BUS_CHUNK);
                if ($part === '' && $chunks > 0) break;
                $changes['res/' . $id . '.' . $chunks . '.json'] = json_encode(bus_seal($keys, 'chunk', $id, $part, $exp));
                $chunks++;
                if ($part === '') break;
            }
            fclose($fh);
        }
        @unlink($tmp);
        $res['chunks'] = $chunks;
        $changes['res/' . $id . '.json'] = json_encode(bus_seal($keys, 'res', $id, json_encode($res), $exp));
        $n++;
    }
    // housekeeping: answers older than an hour go (the client reads within seconds)
    $gone = $st['res_seen'] ?? [];
    foreach ($old as $path => $id) { if (isset($gone[$id]) && $gone[$id] < $now - 3600) $changes[$path] = null; elseif (!isset($gone[$id])) $gone[$id] = $now; }
    foreach ($gone as $id => $t) if ($t < $now - 7200) unset($gone[$id]);
    $st['res_seen'] = $gone;
    file_put_contents($seenF, json_encode($seen));
    if (bus_commit($changes, "bus: $n answer(s)")) { $st['answered'] = ($st['answered'] ?? 0) + $n; $st['last_answer_at'] = $now; $st['etag'] = ''; }
    else {   // nothing landed: forget these ids so the next poll answers them again
        $st['last_error'] = 'commit failed';
        foreach (array_keys($reqs) as $id) unset($seen[$id]);
        file_put_contents($seenF, json_encode($seen));
        $st['etag'] = '';
    }
    return $n;
}

/** Commit a set of path changes on top of the branch head (Git Data API), retrying on a moved head. */
function bus_commit(array $changes, string $msg): bool {
    $base = '/repos/' . BUS_REPO . '/git/';
    for ($try = 0; $try < 4; $try++) {
        [$c, $ref] = ops_gh('GET', $base . 'ref/heads/' . BUS_BRANCH);
        if ($c !== 200) return false;
        $head = $ref['object']['sha'];
        [$c, $cm] = ops_gh('GET', $base . 'commits/' . $head);
        if ($c !== 200) return false;
        $items = [];
        foreach ($changes as $path => $content) {
            if ($content === null) { $items[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null]; continue; }
            [$cb, $b] = ops_gh('POST', $base . 'blobs', ['content' => base64_encode($content), 'encoding' => 'base64']);
            if ($cb !== 201) return false;
            $items[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $b['sha']];
        }
        [$ct, $t] = ops_gh('POST', $base . 'trees', ['base_tree' => $cm['tree']['sha'], 'tree' => $items]);
        if ($ct !== 201) { // deleting a path that a racing commit already removed fails the whole tree: drop deletes and retry
            $changes = array_filter($changes, fn($v) => $v !== null); if (!$changes) return true; continue;
        }
        [$cc, $nc] = ops_gh('POST', $base . 'commits', ['message' => $msg, 'tree' => $t['sha'], 'parents' => [$head]]);
        if ($cc !== 201) return false;
        [$cu, ] = ops_gh('PATCH', $base . 'refs/heads/' . BUS_BRANCH, ['sha' => $nc['sha'], 'force' => false]);
        if ($cu === 200) return true;
        usleep(300000);
    }
    return false;
}

/** Long tick: poll for $seconds (called by the 5-minute cron, so the bus is effectively always on). */
function bus_tick(int $seconds = 285): void {
    $lock = fopen(ops_var('bus.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;   // the previous long tick is still polling
    $stF = ops_var('bus-state.json');
    $st = @json_decode((string) @file_get_contents($stF), true) ?: [];
    $keys = bus_keys();
    $st['key'] = $keys !== null;
    if ($keys === null || ops_github_token() === '') { $st['at'] = time(); file_put_contents($stF, json_encode($st)); return; }
    $end = time() + $seconds;
    while (time() < $end) {
        $st['polled_at'] = time();
        $n = bus_poll($keys, $st);
        file_put_contents($stF, json_encode($st));
        if ($n === 0) sleep(3);
    }
    flock($lock, LOCK_UN);
}
