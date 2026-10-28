<?php
// Named jobs of studio.hohedoc.ir. Each replaces one private-repo GitHub Actions workflow:
//   higgsfield.yml             → hf_check · hf_submit · hf_status            (inline, seconds)
//   visual-assets-to-drive.yml → asset_upload (inline) · asset_import (queued)
//   remotion-render.yml        → the render runs inside the Claude Code session (Node + Chromium), then asset_upload
//   sd-photos-inventory.yml    → sd_inventory (queued)
//   sd-photos-fetch.yml        → sd_fetch (queued; output = expiring token link)
// Queued jobs are picked up by app/tick.php (cron, every minute, no web time limit).
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/hfmcp.php';

const HF_API = 'https://api.higgsfield.ai';
const ASSET_KINDS = ['picture', 'video', 'motion', 'audio', 'avatar'];
const ASSET_AGENT = 'Orchestrator-Content-Video-Picture-Creator';
const ASSET_KIND_AGENT = ['audio' => 'Orchestrator-Content-Audio-Creator', 'avatar' => 'Orchestrator-Content-Avatar-Creator'];
const ASSET_NAME_RE = '/^[a-z0-9][a-z0-9._-]{0,150}\.(png|jpe?g|webp|gif|mp4|webm|mov|mp3|wav|m4a|ogg|json)$/';
const IMPORT_MAX_BYTES = 500 * 1024 * 1024;
const OUT_TTL_S = 6 * 3600;
const HF_DAILY_MAX_DEFAULT = 30;   // credit-burn cap if the key ever leaks; override with HF_DAILY_MAX

// ---------------------------------------------------------------- Higgsfield
// Gives the owner a 15-minute one-time link to hf-login.php (the browser cannot send the door header).
function job_hf_login_link(array $a): array {
    $t = bin2hex(random_bytes(16));
    file_put_contents(studio_var('hf-login-link.txt'), $t); chmod(studio_var('hf-login-link.txt'), 0600);
    return ['open_in_browser' => hf_base() . '/hf-login.php?k=' . $t, 'valid_minutes' => 15];
}

function hf_call(string $method, string $url, $payload = null, int $timeout = 60): array {
    $id = studio_secret('HF_API_KEY_ID'); $sec = studio_secret('HF_API_KEY_SECRET');
    if ($id === '' || $sec === '') throw new RuntimeException('HF_API_KEY_ID/HF_API_KEY_SECRET missing in secrets — add them with a secrets-extra file');
    $h = ['Authorization: Key ' . $id . ':' . $sec, 'Accept: application/json'];
    $body = null;
    if ($payload !== null) { $h[] = 'Content-Type: application/json'; $body = json_encode($payload); }
    return studio_http($method, $url, $h, $body, $timeout);
}

function job_hf_check(array $a): array {
    [$code, $r] = hf_call('POST', HF_API . '/higgsfield-ai/soul/v2/standard', new stdClass());
    // Same reading as higgsfield_api.py check: 401/403 = key rejected; 400/404/405/422/2xx = auth layer passed.
    return ['key_ok' => $code !== 401 && $code !== 403 && $code < 500, 'http' => $code];
}

function job_hf_submit(array $a): array {
    $path = (string) ($a['path'] ?? 'higgsfield-ai/soul/v2/standard');
    if (!preg_match('#^[a-z0-9-]+(/[a-z0-9._-]+){1,5}$#', $path)) throw new RuntimeException('bad model path');
    $body = json_decode((string) ($a['body'] ?? ''));   // object, not assoc: "{}" must stay {} and not become []
    if (!is_object($body)) throw new RuntimeException('body must be a JSON object');
    $cnt = studio_var('hf-count-' . gmdate('Ymd') . '.txt');
    $n = (int) @file_get_contents($cnt);
    $max = (int) (studio_secret('HF_DAILY_MAX') ?: HF_DAILY_MAX_DEFAULT);
    if ($n >= $max) throw new RuntimeException("daily Higgsfield cap reached ($max) — raise HF_DAILY_MAX if intended");
    file_put_contents($cnt, (string) ($n + 1), LOCK_EX);
    [$code, $r] = hf_call('POST', HF_API . '/' . $path, $body);
    if ($code >= 400) throw new RuntimeException("higgsfield rejected ($code): " . substr(json_encode($r), 0, 400));
    return ['request_id' => $r['request_id'] ?? null, 'status_url' => $r['status_url'] ?? null, 'raw' => $r];
}

function job_hf_status(array $a): array {
    $rid = (string) ($a['request_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $rid)) throw new RuntimeException('bad request_id');
    [$code, $r] = hf_call('GET', HF_API . '/requests/' . $rid . '/status');
    if ($code >= 400) throw new RuntimeException("status failed ($code)");
    return is_array($r) ? $r : ['raw' => $r];
}

// ---------------------------------------------------------------- visual assets → Drive
function asset_check(string $kind, string $name): void {
    if (!in_array($kind, ASSET_KINDS, true)) throw new RuntimeException('kind must be one of ' . implode('/', ASSET_KINDS));
    if (!preg_match(ASSET_NAME_RE, $name)) throw new RuntimeException('bad name (lowercase, digits, . _ - and a media extension)');
}

function asset_folder(string $kind, string $name): string {
    $agent = drive_ensure_folder(ASSET_KIND_AGENT[$kind] ?? ASSET_AGENT, drive_root_id());
    $folder = drive_ensure_folder($kind, $agent);
    if ($kind === 'avatar' && strpos($name, '__') !== false) $folder = drive_ensure_folder(explode('__', $name, 2)[0], $folder);
    return $folder;
}

// The bytes must match the extension's family (image/video/audio/json), not just the name.
function asset_mime_ok(string $path, string $name): void {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $fam = ['png' => 'image/', 'jpg' => 'image/', 'jpeg' => 'image/', 'webp' => 'image/', 'gif' => 'image/',
        'mp4' => 'video/', 'webm' => 'video/', 'mov' => 'video/', 'mp3' => 'audio/', 'wav' => 'audio/',
        'm4a' => 'audio/', 'ogg' => 'audio/', 'json' => 'application/json'][$ext] ?? 'x';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    $okAlt = ($fam === 'audio/' && in_array($mime, ['video/mp4', 'video/webm', 'application/ogg', 'audio/x-wav'], true))
        || ($fam === 'application/json' && $mime === 'text/plain');
    if (strpos($mime, $fam) !== 0 && !$okAlt) throw new RuntimeException("content is $mime, not a .$ext file");
}

// Same record shape as visual_assets_drive.upload(), so the session can call add_index_row() unchanged.
function asset_put(string $kind, string $local, string $name): array {
    $md5 = md5_file($local);
    $folder = asset_folder($kind, $name);
    $ex = drive_find($folder, $name);
    if ($ex && ($ex['md5Checksum'] ?? '') === $md5) {
        return ['kind' => $kind, 'name' => $name, 'drive_id' => $ex['id'], 'web_view_link' => $ex['webViewLink'] ?? '',
            'md5' => $md5, 'verified' => true, 'skipped_existing' => true];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($local) ?: 'application/octet-stream';
    $r = drive_upload_file($local, $name, $folder, $mime);
    return ['kind' => $kind, 'name' => $name, 'drive_id' => $r['id'], 'web_view_link' => $r['webViewLink'] ?? '',
        'md5' => $md5, 'verified' => ($r['md5Checksum'] ?? '') === $md5, 'skipped_existing' => false];
}

// POST multipart: file=<media> kind=<kind> name=<name> [md5=<hex>]. Provenance checks run in the
// Claude session BEFORE upload (they need python, numpy and the audio library). Nothing lands under public_html.
function job_asset_upload(array $a): array {
    $kind = (string) ($a['kind'] ?? ''); $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('no file (POST multipart field "file", ≤64MB)');
    $name = (string) ($a['name'] ?? $f['name']);
    asset_check($kind, $name);
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('not an upload');
    asset_mime_ok($f['tmp_name'], $name);
    if (($a['md5'] ?? '') !== '' && md5_file($f['tmp_name']) !== $a['md5']) throw new RuntimeException('md5 mismatch in transit');
    // The Drive leg can outlast a web request, so the file waits in var/inbox/ and the cron sends it.
    $inbox = studio_var('inbox'); if (!is_dir($inbox)) mkdir($inbox, 0700, true);
    $held = $inbox . '/' . bin2hex(random_bytes(8));
    if (!move_uploaded_file($f['tmp_name'], $held)) throw new RuntimeException('could not hold the upload');
    return job_enqueue('asset_send', ['kind' => $kind, 'name' => $name, 'held' => basename($held)]);
}

function run_asset_send(array $a): array {
    $held = studio_var('inbox/' . preg_replace('/[^a-f0-9]/', '', (string) ($a['held'] ?? '')));
    if (!is_file($held)) throw new RuntimeException('held file is gone');
    asset_check((string) $a['kind'], (string) $a['name']);
    try { return asset_put((string) $a['kind'], $held, (string) $a['name']); }
    finally { @unlink($held); }
}

// SSRF guard for a caller-supplied URL: https only, the name must resolve to public addresses only, and the
// connection is pinned to the checked address (no DNS rebinding). Redirects are followed by hand, re-checked each hop.
function public_https_pin(string $url): array {
    $u = parse_url($url);
    if (($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user'])) throw new RuntimeException('url must be plain https');
    $host = $u['host']; $port = (int) ($u['port'] ?? 443);
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) throw new RuntimeException('host does not resolve');
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('url points to a private address');
    }
    return ["$host:$port:" . $ips[0]];
}

function fetch_public(string $url, string $dest, int $max): int {
    for ($hop = 0; $hop < 5; $hop++) {
        $loc = null;
        $n = fetch_to_file($url, $dest, $max, [], public_https_pin($url), $loc);
        if ($loc === null) return $n;
        $url = (string) $loc;
    }
    throw new RuntimeException('too many redirects');
}

function fetch_to_file(string $url, string $dest, int $max, array $headers = [], ?array $resolve = null, &$redirect = null): int {
    $fh = fopen($dest, 'wb'); $got = 0;
    $ch = curl_init($url);
    $follow = $resolve === null;   // trusted endpoints (GitHub/Drive) may redirect freely; public URLs are re-checked per hop
    if ($resolve) curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 900,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'studio-host',
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($fh, &$got, $max) {
            $got += strlen($data);
            return $got > $max ? 0 : fwrite($fh, $data);
        }]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirect = (!$follow && $code >= 300 && $code < 400) ? curl_getinfo($ch, CURLINFO_REDIRECT_URL) : null;
    curl_close($ch); fclose($fh);
    if ($redirect) { @unlink($dest); return 0; }
    if ($got > $max) { @unlink($dest); throw new RuntimeException('download larger than cap'); }
    if ($code !== 200 || $got === 0) { @unlink($dest); throw new RuntimeException("download failed ($code)"); }
    return $got;
}

// Replaces outbox/imports.tsv: an output that lives only in the maker service (e.g. Higgsfield history).
function run_asset_import(array $a): array {
    $kind = (string) ($a['kind'] ?? ''); $name = (string) ($a['name'] ?? ''); $url = (string) ($a['url'] ?? '');
    asset_check($kind, $name);
    $tmp = studio_var('tmp-' . bin2hex(random_bytes(6)));
    try { fetch_public($url, $tmp, IMPORT_MAX_BYTES); asset_mime_ok($tmp, $name); return asset_put($kind, $tmp, $name); }
    finally { @unlink($tmp); }
}

// ---------------------------------------------------------------- stylist-directory photos
const SD_IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/jpg'];
const SD_HINTS = ['stylist', 'salon', 'سالن', 'استایلیست', 'yousef', 'یوسف', 'photo', 'عکس', 'image'];
const SD_VARIANTS = ['thumb' => 320, 'medium' => 800, 'full' => 1600];
const SD_WEBP_QUALITY = 82;

function sd_walk(string $fid, int $depth, array &$acc, int $max = 4): void {
    foreach (drive_list("'$fid' in parents and trashed=false", 'id,name,mimeType,size,parents') as $k) {
        if (($k['mimeType'] ?? '') === FOLDER_MIME) {
            $acc['folders'][] = ['id' => $k['id'], 'name' => $k['name'], 'depth' => $depth + 1];
            if ($depth + 1 < $max) sd_walk($k['id'], $depth + 1, $acc, $max);
        } elseif (in_array($k['mimeType'] ?? '', SD_IMAGE_MIME, true)) {
            $acc['images']++; $acc['bytes'] += (int) ($k['size'] ?? 0);
            if (count($acc['samples']) < 25) $acc['samples'][] = ['folder_id' => $fid, 'name' => $k['name'], 'id' => $k['id']];
        }
    }
}

// Same JSON shape as sd_photos_drive.py inventory; the session commits it to Memory-Repository/stylistdirectory/.
function run_sd_inventory(array $a): array {
    $folder = (string) ($a['folder'] ?? '');
    if ($folder !== '' && !preg_match('/^[A-Za-z0-9_-]{10,80}$/', $folder)) throw new RuntimeException('bad folder id');
    $roots = [];
    if ($folder !== '') $roots[] = ['id' => $folder, 'name' => '(داده‌شده با ورودی)'];
    else foreach (SD_HINTS as $h) {
        $q = "mimeType='" . FOLDER_MIME . "' and trashed=false and name contains '" . drive_q_escape($h) . "'";
        foreach (drive_list($q, 'id,name,parents,modifiedTime', 200) as $f) $roots[$f['id']] = $f;
    }
    $report = ['roots' => []];
    if (!$roots) $report['note'] = 'هیچ پوشهٔ نامزدی پیدا نشد — با folder شناسه بده.';
    foreach (array_slice(array_values($roots), 0, 12) as $r) {
        $acc = ['folders' => [], 'images' => 0, 'bytes' => 0, 'samples' => []];
        sd_walk($r['id'], 0, $acc);
        $report['roots'][] = ['id' => $r['id'], 'name' => $r['name'] ?? null, 'image_count' => $acc['images'],
            'total_bytes' => $acc['bytes'], 'subfolder_count' => count($acc['folders']),
            'subfolders' => array_slice($acc['folders'], 0, 60), 'sample_files' => $acc['samples']];
    }
    usort($report['roots'], fn($x, $y) => $y['image_count'] <=> $x['image_count']);
    return $report;
}

function sd_parse_name(string $fname): array {
    $base = pathinfo($fname, PATHINFO_FILENAME);
    return preg_match('/^(.+?)--.*?(\d+)$/u', $base, $m) ? [$m[1], (int) $m[2]] : [null, null];
}

const SD_MAX_PIXELS = 50_000_000;   // ~200 MB as a GD truecolor image; bigger photos are reported, not opened

function sd_load_image(string $path) {
    $info = @getimagesize($path);
    if (!$info || $info[0] * $info[1] > SD_MAX_PIXELS) return null;
    $im = @imagecreatefromstring((string) file_get_contents($path));   // jpeg/png/webp/gif; HEIC is not decodable by GD
    if (!$im) return null;
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);           // imagewebp silently writes junk from palette images
    $w = imagesx($im); $h = imagesy($im);                             // flatten transparency on white, as PIL convert("RGB")
    $flat = imagecreatetruecolor($w, $h);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $im, 0, 0, 0, 0, $w, $h); imagedestroy($im); $im = $flat;
    if (($info[2] ?? 0) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        if (in_array($o, [2, 4, 5, 7], true)) imageflip($im, IMG_FLIP_HORIZONTAL);
        $rot = [3 => 180, 4 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($im, $rot, 0); imagedestroy($im); $im = $r; }
    }
    return $im;
}

// Downscale with imagecopyresampled (closer to PIL LANCZOS than imagescale's bicubic).
function sd_resize($src, int $width) {
    $w = imagesx($src); $h = imagesy($src);
    if ($w <= $width) return $src;
    $nh = max(1, (int) round($h * $width / $w));
    $dst = imagecreatetruecolor($width, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $nh, $w, $h);
    return $dst;
}

// Fetch photos and build three WebP sizes; the result is a zip behind an unguessable, expiring link.
// map = the JSON object of Memory-Repository/stylistdirectory/photo-map.json, passed by the session.
function run_sd_fetch(array $a): array {
    if (!function_exists('imagewebp')) throw new RuntimeException('GD on this PHP has no WebP — run sd_fetch on the public repo instead');
    $folder = (string) ($a['folder'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $folder)) throw new RuntimeException('bad folder id');
    $map = is_array($a['map'] ?? null) ? $a['map'] : (json_decode((string) ($a['map'] ?? '{}'), true) ?: []);
    $token = studio_new_token();
    $out = studio_out_dir($token); mkdir($out, 0700, true);
    file_put_contents("$out/.expires", (string) (time() + OUT_TTL_S));   // first, so a crash mid-run still gets cleaned
    $manifest = []; $unmapped = []; $undecodable = [];
    $scopes = [[$folder, '']];
    foreach (drive_list("'$folder' in parents and trashed=false and mimeType='" . FOLDER_MIME . "'", 'id,name') as $s) $scopes[] = [$s['id'], $s['name']];
    foreach ($scopes as [$fid, $sub]) {
        foreach (drive_list("'$fid' in parents and trashed=false", 'id,name,mimeType,size') as $f) {
            if (!in_array($f['mimeType'] ?? '', SD_IMAGE_MIME, true)) continue;
            [$key, $idx] = sd_parse_name($f['name']);
            $slug = $key ? ($map[$sub][$key] ?? null) : null;
            if (!$slug || !$idx || !preg_match('/^[a-z0-9-]{1,80}$/', $slug)) { $unmapped[] = ['folder' => $sub, 'name' => $f['name']]; continue; }
            $tmp = studio_var('tmp-' . bin2hex(random_bytes(6)));
            drive_download($f['id'], $tmp);
            $img = sd_load_image($tmp); @unlink($tmp);
            if (!$img) { $undecodable[] = ['folder' => $sub, 'name' => $f['name']]; continue; }
            @mkdir("$out/$slug", 0700, true);
            $src = $img;   // chain 1600 → 800 → 320, each from the previous size
            foreach (array_reverse(SD_VARIANTS, true) as $variant => $width) {
                $im = sd_resize($src, $width);
                $rel = sprintf('%s/%02d-%s.webp', $slug, $idx, $variant);
                imagewebp($im, "$out/$rel", SD_WEBP_QUALITY);
                $manifest[] = ['slug' => $slug, 'sub' => $sub, 'sort_no' => $idx, 'variant' => $variant, 'file' => $rel,
                    'width_px' => imagesx($im), 'height_px' => imagesy($im), 'bytes' => filesize("$out/$rel"),
                    'sha256' => hash_file('sha256', "$out/$rel"), 'drive_name' => $f['name']];
                if ($src !== $img && $src !== $im) imagedestroy($src);
                $src = $im;
            }
            if ($src !== $img) imagedestroy($src);
            imagedestroy($img);
        }
    }
    file_put_contents("$out/manifest.json", json_encode(['items' => $manifest, 'unmapped' => $unmapped, 'undecodable' => $undecodable], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $z = new ZipArchive();
    if ($z->open("$out/sd-photos.zip", ZipArchive::CREATE) !== true) throw new RuntimeException('cannot create zip');
    $loose = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $p) {
        $rel = substr($p->getPathname(), strlen($out) + 1);
        if ($rel === 'sd-photos.zip' || $rel === '.expires') continue;
        $z->addFile($p->getPathname(), $rel);
        if (str_ends_with($rel, '.webp')) $z->setCompressionName($rel, ZipArchive::CM_STORE);   // already compressed
        $loose[] = $p->getPathname();
    }
    if (!$z->close()) throw new RuntimeException('zip write failed');
    foreach ($loose as $l) @unlink($l);                                 // 3 GB disk: keep only the zip
    foreach (glob("$out/*", GLOB_ONLYDIR) ?: [] as $d) @rmdir($d);
    return ['made' => count($manifest), 'unmapped' => count($unmapped), 'undecodable' => $undecodable,
        'download' => 'get.php?t=' . $token, 'expires_in_h' => OUT_TTL_S / 3600];
}

// ---------------------------------------------------------------- queue (cron-driven, no web time limit)
const QUEUED = ['asset_import', 'sd_inventory', 'sd_fetch'];   // callable through the door
const INTERNAL = ['asset_send'];                              // only enqueued by the host itself

function queue_dir(): string { $d = studio_var('queue'); if (!is_dir($d)) mkdir($d, 0700, true); return $d; }

function queue_write(string $p, array $q): void {   // atomic: a reader never sees half a JSON
    file_put_contents("$p.tmp", json_encode($q, JSON_UNESCAPED_UNICODE)); rename("$p.tmp", $p);
}

function job_enqueue(string $name, array $args): array {
    $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    queue_write(queue_dir() . "/$id.json", ['job' => $name, 'args' => $args, 'state' => 'queued', 'at' => time(), 'tries' => 0]);
    return ['queued' => true, 'id' => $id, 'poll' => "job=result&id=$id"];
}

function job_result(array $a): array {
    $id = (string) ($a['id'] ?? '');
    if (!preg_match('/^\d{14}-[a-f0-9]{8}$/', $id)) throw new RuntimeException('bad id');
    $p = queue_dir() . "/$id.json";
    if (!is_file($p)) throw new RuntimeException('unknown id');
    $q = json_decode((string) file_get_contents($p), true) ?: [];
    unset($q['args']['map']);   // keep the answer small
    return $q;
}

// Call only while holding tick.lock (app/tick.php): one runner at a time, so a job left 'running' is an orphan
// of a killed process and goes back to the queue (at most 3 tries).
function queue_run_one(): ?string {
    foreach (glob(queue_dir() . '/*.json') ?: [] as $p) {
        $q = json_decode((string) file_get_contents($p), true);
        if (!is_array($q)) continue;
        if (($q['state'] ?? '') === 'running') { $q['state'] = ($q['tries'] ?? 0) >= 3 ? 'failed' : 'queued'; $q['error'] = 'runner died'; queue_write($p, $q); }
        if (($q['state'] ?? '') !== 'queued' || !in_array($q['job'] ?? '', array_merge(QUEUED, INTERNAL), true)) continue;
        $q['state'] = 'running'; $q['started'] = time(); $q['tries'] = ($q['tries'] ?? 0) + 1;
        queue_write($p, $q);
        try { $q['result'] = ('run_' . $q['job'])($q['args'] ?? []); $q['state'] = 'done'; unset($q['error']); }
        catch (Throwable $e) { $q['state'] = 'failed'; $q['error'] = $e->getMessage(); }
        $q['ended'] = time();
        queue_write($p, $q);
        studio_log('queue', basename($p) . ' ' . $q['job'] . ' ' . $q['state']);
        return basename($p);
    }
    return null;
}

function queue_cleanup(): void {
    foreach (glob(queue_dir() . '/*.json') ?: [] as $p) if (filemtime($p) < time() - 14 * 86400) unlink($p);
    foreach (glob(studio_var('out') . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        $exp = (int) @file_get_contents("$d/.expires");
        if ($exp && $exp < time()) studio_rrmdir($d);
    }
    foreach (glob(studio_var('tmp-*')) ?: [] as $t) if (filemtime($t) < time() - 86400) unlink($t);
    foreach (glob(studio_var('inbox') . '/*') ?: [] as $t) if (filemtime($t) < time() - 86400) unlink($t);
}
