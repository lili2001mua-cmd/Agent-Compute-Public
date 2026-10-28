<?php
// Shared helpers for studio.hohedoc.ir — the holding's image/video host.
// Secrets live in ../secrets.php (outside public_html, chmod 600); one fixed ../secrets-extra.php with the
// same "return [...]" shape is merged on top (no glob: a stray file must not be able to override a key).
// Keys that belong here: ADMIN_KEY (its own, not trainer's), DRIVE_OAUTH, DRIVE_ROOT_ID, HF_API_KEY_ID,
// HF_API_KEY_SECRET, optional HF_DAILY_MAX. No Telegram session, no GitHub token: least privilege per subdomain.
const STUDIO_ROOT = __DIR__ . '/..';

function studio_secrets(): array {
    static $s = null;
    if ($s === null) {
        $s = [];
        foreach ([STUDIO_ROOT . '/secrets.php', STUDIO_ROOT . '/secrets-extra.php'] as $p) {
            if (is_file($p)) {
                $part = include $p;
                if (is_array($part)) $s = array_merge($s, array_filter($part, fn($v) => $v !== '' && $v !== null));
            }
        }
    }
    return $s;
}

function studio_secret(string $key): string {
    return (string) (studio_secrets()[$key] ?? '');
}

// Door check: key only from the X-Studio-Key header; after 20 wrong keys in 10 minutes the door stays shut.
function studio_key_ok(): bool {
    $f = studio_var('door-fail.json');
    $st = @json_decode((string) @file_get_contents($f), true) ?: ['n' => 0, 't' => 0];
    if (time() - $st['t'] > 600) $st = ['n' => 0, 't' => time()];
    if ($st['n'] >= 20) return false;
    $want = studio_secret('ADMIN_KEY');
    if (strlen($want) >= 32 && hash_equals($want, (string) ($_SERVER['HTTP_X_STUDIO_KEY'] ?? ''))) return true;
    $st['n']++;
    file_put_contents($f, json_encode($st), LOCK_EX);
    return false;
}

function studio_var(string $name): string {
    $dir = STUDIO_ROOT . '/var';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir . '/' . $name;
}

function studio_log(string $stream, string $line): void {
    file_put_contents(studio_var($stream . '.log'), gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

// One HTTP call. Returns [status, decoded-json-or-raw-string]. Never logs URLs or bodies.
function studio_http(string $method, string $url, array $headers = [], $body = null, int $timeout = 60): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'studio-host'];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('network: ' . $err);
    $json = json_decode((string) $raw, true);
    return [$code, is_array($json) ? $json : (string) $raw];
}

// ---------------------------------------------------------------- Google Drive (REST v3, OAuth refresh)
// DRIVE_OAUTH is the same multi-line secret the private repo uses: CLIENT_ID=… / CLIENT_SECRET=… / REFRESH_TOKEN=…
const DRIVE_API = 'https://www.googleapis.com/drive/v3';
const DRIVE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';
const FOLDER_MIME = 'application/vnd.google-apps.folder';

// Memory-Cloud folder id comes from the DRIVE_ROOT_ID secret (same value as pool_media_backend.POOL_PARENT_FOLDER_ID
// in the private repo) — kept out of this public code.
function drive_root_id(): string {
    $id = studio_secret('DRIVE_ROOT_ID');
    if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $id)) throw new RuntimeException('DRIVE_ROOT_ID missing in secrets');
    return $id;
}

// The access token is cached in var/ under a lock, so web requests and the cron don't each refresh it.
function drive_token(bool $force = false): string {
    static $tok = null, $exp = 0;
    if (!$force && $tok !== null && time() < $exp - 60) return $tok;
    $cache = studio_var('drive-token.json');
    $lk = fopen(studio_var('drive-token.lock'), 'c'); flock($lk, LOCK_EX);
    $c = @json_decode((string) @file_get_contents($cache), true);
    if (!$force && is_array($c) && ($c['exp'] ?? 0) > time() + 60) { $tok = $c['tok']; $exp = $c['exp']; flock($lk, LOCK_UN); return $tok; }
    $kv = [];
    foreach (preg_split('/\r?\n/', studio_secret('DRIVE_OAUTH')) as $line) {
        $p = strpos($line, '=');
        if ($p !== false) $kv[trim(substr($line, 0, $p))] = trim(substr($line, $p + 1));
    }
    foreach (['CLIENT_ID', 'CLIENT_SECRET', 'REFRESH_TOKEN'] as $k) {
        if (empty($kv[$k])) { flock($lk, LOCK_UN); throw new RuntimeException("DRIVE_OAUTH has no $k — missing credential, not a code error"); }
    }
    [$code, $r] = studio_http('POST', 'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => $kv['CLIENT_ID'], 'client_secret' => $kv['CLIENT_SECRET'],
            'refresh_token' => $kv['REFRESH_TOKEN'], 'grant_type' => 'refresh_token']));
    if ($code !== 200 || empty($r['access_token'])) { flock($lk, LOCK_UN); throw new RuntimeException("drive token refresh failed ($code)"); }
    $tok = $r['access_token'];
    $exp = time() + (int) ($r['expires_in'] ?? 3000);
    file_put_contents($cache . '.tmp', json_encode(['tok' => $tok, 'exp' => $exp])); chmod($cache . '.tmp', 0600); rename($cache . '.tmp', $cache);
    flock($lk, LOCK_UN);
    return $tok;
}

function drive_call(string $method, string $path, array $query = [], $json = null): array {
    $url = DRIVE_API . $path . ($query ? '?' . http_build_query($query) : '');
    $body = $json !== null ? json_encode($json) : null;
    for ($try = 0; ; $try++) {
        $h = ['Authorization: Bearer ' . drive_token($try > 0 && ($code ?? 0) === 401)];
        if ($body !== null) $h[] = 'Content-Type: application/json';
        [$code, $r] = studio_http($method, $url, $h, $body);
        $rate = $code === 403 && is_array($r) && preg_match('/rateLimitExceeded|userRateLimitExceeded/', json_encode($r));
        if ($try >= 4 || !($code === 401 || $code === 429 || $code >= 500 || $rate)) break;
        sleep(min(16, 2 ** $try));
    }
    if ($code >= 400) throw new RuntimeException("drive $method $path → $code " . substr(is_array($r) ? json_encode($r) : $r, 0, 300));
    return is_array($r) ? $r : [];
}

function drive_q_escape(string $s): string {
    return str_replace(["\\", "'"], ["\\\\", "\\'"], $s);
}

// All pages of files.list, capped at $limit.
function drive_list(string $q, string $fields, int $limit = 1000, string $orderBy = ''): array {
    $out = []; $token = null;
    do {
        $query = ['q' => $q, 'fields' => "nextPageToken, files($fields)", 'pageSize' => 200,
            'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true'];
        if ($orderBy !== '') $query['orderBy'] = $orderBy;
        if ($token) $query['pageToken'] = $token;
        $r = drive_call('GET', '/files', $query);
        $out = array_merge($out, $r['files'] ?? []);
        $token = $r['nextPageToken'] ?? null;
    } while ($token && count($out) < $limit);
    return array_slice($out, 0, $limit);
}

function drive_ensure_folder(string $name, string $parent): string {
    $lk = fopen(studio_var('drive-folder.lock'), 'c'); flock($lk, LOCK_EX);   // Drive accepts duplicate names
    try { return drive_ensure_folder_locked($name, $parent); } finally { flock($lk, LOCK_UN); }
}

function drive_ensure_folder_locked(string $name, string $parent): string {
    $q = "mimeType='" . FOLDER_MIME . "' and name='" . drive_q_escape($name) . "' and trashed=false and '$parent' in parents";
    $hit = drive_list($q, 'id,name', 1);
    if ($hit) return $hit[0]['id'];
    $r = drive_call('POST', '/files', ['fields' => 'id', 'supportsAllDrives' => 'true'],
        ['name' => $name, 'mimeType' => FOLDER_MIME, 'parents' => [$parent]]);
    return $r['id'];
}

function drive_find(string $parent, string $name): ?array {
    $q = "name='" . drive_q_escape($name) . "' and trashed=false and '$parent' in parents";
    $hit = drive_list($q, 'id,name,md5Checksum,webViewLink,size', 1, 'modifiedTime desc');
    return $hit[0] ?? null;
}

// Resumable upload streamed from disk in 8 MiB chunks. Follows the protocol strictly: after each 308 the
// server's Range header says how much it really has; on 5xx/429/network loss we ask (Content-Range: bytes */SIZE)
// and resume from there; 404/410 means the session is gone and the whole upload starts over (once).
function drive_upload_file(string $path, string $name, string $parent, string $mime = 'application/octet-stream'): array {
    $size = filesize($path);
    if (!$size) throw new RuntimeException('empty file refused');
    for ($fresh = 0; $fresh < 2; $fresh++) {
        $session = drive_upload_session($name, $parent, $mime, $size);
        $fh = fopen($path, 'rb'); $off = 0; $fails = 0;
        while (true) {
            fseek($fh, $off);
            $data = (string) fread($fh, 8 * 1024 * 1024);
            $len = strlen($data);
            [$c, $r, $hdr] = upload_put($session, $data, 'bytes ' . $off . '-' . ($off + $len - 1) . '/' . $size);
            if ($c === 200 || $c === 201) { fclose($fh); return $r; }
            if ($c === 404 || $c === 410) break;                       // session expired → start over
            if ($c !== 308) {                                         // transient: ask the server where it is
                if (++$fails > 5 || ($c >= 400 && $c < 500 && $c !== 429)) { fclose($fh); throw new RuntimeException("drive chunk upload failed ($c)"); }
                sleep(min(16, 2 ** $fails));
                [$c, $r, $hdr] = upload_put($session, '', 'bytes */' . $size);
                if ($c === 200 || $c === 201) { fclose($fh); return $r; }
                if ($c === 404 || $c === 410) break;
            }
            $off = preg_match('/^range:\s*bytes=0-(\d+)/mi', $hdr, $m) ? (int) $m[1] + 1 : 0;
        }
        fclose($fh);
    }
    throw new RuntimeException('drive upload session lost twice');
}

function drive_upload_session(string $name, string $parent, string $mime, int $size): string {
    $h = ['Authorization: Bearer ' . drive_token(), 'Content-Type: application/json; charset=UTF-8',
        'X-Upload-Content-Type: ' . $mime, 'X-Upload-Content-Length: ' . $size];
    $ch = curl_init(DRIVE_UPLOAD . '?uploadType=resumable&supportsAllDrives=true&fields=id,name,webViewLink,md5Checksum');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => json_encode(['name' => $name, 'parents' => [$parent]]),
        CURLOPT_TIMEOUT => 60]);
    $resp = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !preg_match('/^location:\s*(\S+)/mi', $resp, $m)) throw new RuntimeException("drive upload session failed ($code)");
    return $m[1];
}

// One PUT to an upload session → [status, json-or-raw, raw headers]. Network loss reads as status 0.
function upload_put(string $session, string $data, string $range): array {
    $ch = curl_init($session);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $data, CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => ['Content-Length: ' . strlen($data), 'Content-Range: ' . $range]]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($resp === false) return [0, null, ''];
    $hdr = substr($resp, 0, $hs); $body = substr($resp, $hs);
    $j = json_decode($body, true);
    return [$code, is_array($j) ? $j : $body, $hdr];
}

function drive_download(string $fileId, string $dest): int {
    $fh = fopen($dest, 'wb');
    $ch = curl_init(DRIVE_API . '/files/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true');
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 300, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . drive_token()]]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);
    if ($code !== 200) { @unlink($dest); throw new RuntimeException("drive download failed ($code)"); }
    return filesize($dest);
}

// ---------------------------------------------------------------- signed, expiring download links
function studio_out_dir(string $token): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new RuntimeException('bad token');
    return studio_var('out/' . $token);
}

function studio_new_token(): string {
    return bin2hex(random_bytes(16));
}

function studio_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
