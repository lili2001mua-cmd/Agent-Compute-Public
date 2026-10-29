<?php
// Google Drive half of sync-routing-digest on the host (user's decision 1405-08-07: zero Actions). Same jobs as
// Sys.scripts/sync-drive-bridge.py and Sys.scripts/push-routing-card-to-drive.py in Agent-Project-Backup:
//   • every file of Sys.drive-bridge/ (no dot files, no README.md) is overwritten in place in the bridge folder;
//   • routing-card.md = the header list of push-routing-card-to-drive.py + the DIGEST block of capability-map.md.
// Both read from main through the GitHub API, so there is one source and no copy of the text here.
// Credentials beside secrets.php (never in public_html, never printed): drive-sa.json (service account: update
// and delete in place) and drive-oauth.env (owner OAuth: only to create a file the folder does not have yet,
// because a service account has no storage quota). Runs every 30 min; uploads only what changed (daily drift check).
require_once __DIR__ . '/routing.php';   // ops_apb_raw(), ops_gh()

const OPS_DRIVE_FOLDER = '1DASI57ZafT4JE50uwbmf8FqcCq1nN3BG';
const OPS_DRIVE_MIME = ['md' => 'text/markdown', 'txt' => 'text/plain', 'json' => 'application/json', 'yaml' => 'text/yaml', 'yml' => 'text/yaml'];

function ops_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function ops_http(string $method, string $url, array $headers, ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $raw];
}

// kind 'sa' = service account (JWT, RS256) · 'oauth' = owner refresh token. '' when not available.
function ops_drive_token(string $kind): string {
    static $cache = [];
    if (isset($cache[$kind])) return $cache[$kind];
    $form = null;
    if ($kind === 'sa') {
        $sa = @json_decode((string) @file_get_contents(OPS_ROOT . '/drive-sa.json'), true);
        if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) return $cache[$kind] = '';
        $now = time();
        $unsigned = ops_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . ops_b64url(json_encode([
            'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/drive',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
        if (!openssl_sign($unsigned, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) return $cache[$kind] = '';
        $form = ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . ops_b64url($sig)];
    } else {
        $env = [];
        foreach (explode("\n", (string) @file_get_contents(OPS_ROOT . '/drive-oauth.env')) as $ln) {
            [$k, $v] = array_pad(explode('=', $ln, 2), 2, '');
            if (trim($k) !== '') $env[trim($k)] = trim($v);
        }
        if (empty($env['CLIENT_ID']) || empty($env['CLIENT_SECRET']) || empty($env['REFRESH_TOKEN'])) return $cache[$kind] = '';
        $form = ['grant_type' => 'refresh_token', 'client_id' => $env['CLIENT_ID'], 'client_secret' => $env['CLIENT_SECRET'], 'refresh_token' => $env['REFRESH_TOKEN']];
    }
    [$c, $raw] = ops_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query($form));
    $j = json_decode($raw, true);
    return $cache[$kind] = ($c === 200 && !empty($j['access_token'])) ? $j['access_token'] : '';
}

function ops_drive_find(string $tok, string $name): array {
    $q = "name = '" . str_replace("'", "\\'", $name) . "' and '" . OPS_DRIVE_FOLDER . "' in parents and trashed = false";
    [$c, $raw] = ops_http('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $q, 'fields' => 'files(id,name,createdTime)',
        'orderBy' => 'createdTime', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']), ["Authorization: Bearer $tok"]);
    $j = json_decode($raw, true);
    return $c === 200 ? array_column($j['files'] ?? [], 'id') : ['__error__' . $c];
}

// Overwrite in place (service account); create with owner OAuth only if missing; delete duplicates. Returns a short word.
function ops_drive_put(string $name, string $content): string {
    $sa = ops_drive_token('sa');
    if ($sa === '') return 'error: no service-account token';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = OPS_DRIVE_MIME[$ext] ?? 'text/markdown';
    $ids = ops_drive_find($sa, $name);
    if ($ids && str_starts_with($ids[0], '__error__')) return 'error: list ' . substr($ids[0], 9);
    if ($ids) {
        [$c] = ops_http('PATCH', 'https://www.googleapis.com/upload/drive/v3/files/' . $ids[0] . '?uploadType=media&supportsAllDrives=true',
            ["Authorization: Bearer $sa", "Content-Type: $mime"], $content);
        if ($c !== 200) return "error: update $c";
        foreach (array_slice($ids, 1) as $extra) ops_http('DELETE', "https://www.googleapis.com/drive/v3/files/$extra?supportsAllDrives=true", ["Authorization: Bearer $sa"]);
        return 'updated';
    }
    $ow = ops_drive_token('oauth');
    if ($ow === '') return 'error: missing on Drive and no owner OAuth to create it';
    $b = 'ops' . bin2hex(random_bytes(8));
    $body = "--$b\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . json_encode(['name' => $name, 'parents' => [OPS_DRIVE_FOLDER]])
          . "\r\n--$b\r\nContent-Type: $mime\r\n\r\n" . $content . "\r\n--$b--";
    [$c] = ops_http('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true',
        ["Authorization: Bearer $ow", "Content-Type: multipart/related; boundary=$b"], $body);
    return in_array($c, [200, 201], true) ? 'created' : "error: create $c";
}

// routing-card.md, built exactly like push-routing-card-to-drive.py: its `header = [...]` lines + the DIGEST block.
function ops_routing_card(): ?string {
    $py = ops_apb_raw('Sys.scripts/push-routing-card-to-drive.py');
    $map = ops_apb_raw('Sys.identity/routing/capability-map.md');
    [$c, $ref] = ops_gh('GET', '/repos/' . OPS_STRANDED_REPO . '/git/ref/heads/main');
    if ($py === null || $map === null || $c !== 200) return null;
    if (!preg_match('/\n    header = \[\n(.*?)\n    \]\n/s', $py, $m)) return null;
    $commit = substr((string) $ref['object']['sha'], 0, 7);
    $header = [];
    foreach (explode("\n", $m[1]) as $ln) {
        if (!preg_match('/^\s*f?"(.*)",\s*$/', $ln, $x)) return null;   // a shape this parser does not know ⇒ stop, not guess
        $header[] = str_replace('{commit}', $commit, (string) json_decode('"' . $x[1] . '"'));
    }
    $digest = []; $in = false;
    foreach (explode("\n", str_replace("\r\n", "\n", $map)) as $ln) {
        if (str_contains($ln, 'DIGEST:START')) { $in = true; continue; }
        if (str_contains($ln, 'DIGEST:END')) break;
        if ($in) $digest[] = $ln;
    }
    return implode("\n", array_merge($header, $digest)) . "\n";
}

function ops_drive_tick(): void {
    if (ops_github_token() === '' || !is_file(OPS_ROOT . '/drive-sa.json')) return;
    $f = ops_var('drive-sync.json');
    $st = @json_decode((string) @file_get_contents($f), true) ?: [];
    if (time() - (int) ($st['checked'] ?? 0) < 1800) return;
    $daily = time() - (int) ($st['full'] ?? 0) > 24 * 3600;
    $st['checked'] = time();
    $seen = $st['seen'] ?? [];
    $res = []; $failed = 0;
    // 1) Sys.drive-bridge/ mirror — the blob sha from the listing says what changed.
    [$c, $list] = ops_gh('GET', '/repos/' . OPS_STRANDED_REPO . '/contents/Sys.drive-bridge?ref=main');
    if ($c !== 200 || !is_array($list)) { $res['_bridge'] = "error: list $c"; $failed++; $list = []; }
    foreach ($list as $e) {
        $n = $e['name'];
        if ($e['type'] !== 'file' || $n[0] === '.' || $n === 'README.md') continue;
        if (!$daily && ($seen[$n] ?? '') === $e['sha']) continue;
        $body = ops_apb_raw('Sys.drive-bridge/' . $n);
        $r = $body === null ? 'error: read' : ops_drive_put($n, $body);
        $res[$n] = $r;
        if (str_starts_with($r, 'error')) $failed++; else $seen[$n] = $e['sha'];
    }
    // 2) routing-card.md — rebuilt every round, uploaded only when its text changed.
    $card = ops_routing_card();
    if ($card === null) { $res['routing-card.md'] = 'error: build'; $failed++; }
    else {
        $h = hash('sha256', preg_replace('/کامیتِ `[0-9a-f]{7}`/u', '', $card));
        if ($daily || ($seen['routing-card.md'] ?? '') !== $h) {
            $r = ops_drive_put('routing-card.md', $card);
            $res['routing-card.md'] = $r;
            if (str_starts_with($r, 'error')) $failed++; else $seen['routing-card.md'] = $h;
        }
    }
    $alerted = $failed ? !empty($st['alerted']) : false;
    $st = ['checked' => time(), 'full' => $daily && !$failed ? time() : (int) ($st['full'] ?? 0), 'seen' => $seen, 'failed' => $failed, 'last' => $res ?: ($st['last'] ?? []), 'alerted' => $alerted];
    if ($res) ops_log('drive ' . json_encode($res, JSON_UNESCAPED_UNICODE));
    if ($failed && empty($st['alerted'])) $st['alerted'] = ops_telegram("⚠️ هاستِ ops: هم‌گام‌سازیِ گوگل‌درایو {$failed} خطا داد. https://ops.hohedoc.ir/status.php");
    file_put_contents($f, json_encode($st, JSON_UNESCAPED_UNICODE));
}
