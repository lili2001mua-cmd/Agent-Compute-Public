<?php
// Named jobs of apps.hohedoc.ir. Each replaces a private-repo GitHub Actions workflow (user decision 1405-08-07,
// orchestration app-build-zero-actions in the private repo):
//   app-release-publish.yml + workout-apk-to-drive.yml → app_upload (inline) → app_send (queued)
//   workout-pdf-to-drive.yml                           → doc_part × n + doc_commit (inline) → doc_send (queued) → Iran relay (1405-08-11)
// The Claude session builds, signs and renders (Java, weasyprint, ffmpeg); this host only puts the bytes on Drive.
// Queued jobs are picked up by app/tick.php (cron, every minute, no web time limit).
require_once __DIR__ . '/lib.php';

// ---------------------------------------------------------------- installable apps → Drive
// Replaces app-release-publish.yml and workout-apk-to-drive.yml (private Actions). The Claude session builds and signs
// the app, runs release-findings --gate, zips each apk/aab on its own (Drive refuses to serve a raw apk), then posts
// the zip here. Drive: <root>/نسخه‌های نصبی/<App>/v<N>/<name>.zip . Same inbox + cron leg as asset_upload.
const APP_RE = '/^[A-Z][A-Za-z0-9-]{1,40}$/';
const APP_ZIP_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.zip$/';
const APP_FOLDER = 'نسخه‌های نصبی';

function app_check(string $app, string $ver, string $name): void {
    if (!preg_match(APP_RE, $app)) throw new RuntimeException('bad app (e.g. Workout-Player)');
    if (!preg_match('/^[0-9]{1,5}$/', $ver)) throw new RuntimeException('bad version (digits only)');
    if (!preg_match(APP_ZIP_RE, $name)) throw new RuntimeException('bad name (letters, digits, . _ - and .zip)');
}

// A plain zip whose every entry is one top-level .apk or .aab — nothing else may ride along to Drive.
function app_zip_ok(string $path): void {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    if ($mime !== 'application/zip') throw new RuntimeException("content is $mime, not a zip");
    if (!class_exists('ZipArchive')) return;
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new RuntimeException('zip does not open');
    try {
        if ($z->numFiles < 1) throw new RuntimeException('empty zip');
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = (string) $z->getNameIndex($i);
            if (!preg_match('/^[^\/]+\.(apk|aab)$/i', $n)) throw new RuntimeException("zip holds $n — only top-level .apk/.aab");
        }
    } finally { $z->close(); }
}

// POST multipart: file=<zip> app=<App> version=<N> name=<x.zip> md5=<hex>. md5 is required: it is the only proof
// that the bytes on Drive are the bytes the session signed.
function job_app_upload(array $a): array {
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('no file (POST multipart field "file", ≤64MB)');
    $app = (string) ($a['app'] ?? ''); $ver = (string) ($a['version'] ?? ''); $name = (string) ($a['name'] ?? $f['name']);
    app_check($app, $ver, $name);
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('not an upload');
    $md5 = (string) ($a['md5'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $md5)) throw new RuntimeException('md5 required');
    if (md5_file($f['tmp_name']) !== $md5) throw new RuntimeException('md5 mismatch in transit');
    app_zip_ok($f['tmp_name']);
    $inbox = apps_var('inbox'); if (!is_dir($inbox)) mkdir($inbox, 0700, true);
    $held = $inbox . '/' . bin2hex(random_bytes(8));
    if (!move_uploaded_file($f['tmp_name'], $held)) throw new RuntimeException('could not hold the upload');
    return job_enqueue('app_send', ['app' => $app, 'version' => $ver, 'name' => $name, 'md5' => $md5, 'held' => basename($held)]);
}

function run_app_send(array $a): array {
    $held = apps_var('inbox/' . preg_replace('/[^a-f0-9]/', '', (string) ($a['held'] ?? '')));
    if (!is_file($held)) throw new RuntimeException('held file is gone');
    $app = (string) $a['app']; $ver = (string) $a['version']; $name = (string) $a['name'];
    app_check($app, $ver, $name);
    try {
        $md5 = md5_file($held);
        if ($md5 !== ($a['md5'] ?? '')) throw new RuntimeException('held file changed');
        $folder = drive_ensure_folder('v' . $ver, drive_ensure_folder($app, drive_ensure_folder(APP_FOLDER, drive_root_id())));
        $ex = drive_find($folder, $name);
        if ($ex && ($ex['md5Checksum'] ?? '') === $md5) {
            return ['app' => $app, 'version' => (int) $ver, 'name' => $name, 'drive_id' => $ex['id'],
                'web_view_link' => $ex['webViewLink'] ?? '', 'md5' => $md5, 'verified' => true, 'skipped_existing' => true];
        }
        $r = drive_upload_file($held, $name, $folder, 'application/zip');
        return ['app' => $app, 'version' => (int) $ver, 'name' => $name, 'drive_id' => $r['id'],
            'web_view_link' => $r['webViewLink'] ?? '', 'md5' => $md5, 'verified' => ($r['md5Checksum'] ?? '') === $md5,
            'skipped_existing' => false];
    } finally { @unlink($held); }
}

// ---------------------------------------------------------------- documents → Drive, in parts
// Replaces workout-pdf-to-drive.yml (private Actions). The session renders the light and heavy PDFs, the program
// bundle and the clip folder (weasyprint/ffmpeg cannot run here), then sends each file in parts of ≤32MB: the bundle
// alone was 64.4MB, above the door's single-request limit. doc_part holds a part, doc_commit queues doc_send, and the
// cron joins the parts, checks the whole md5 and uploads. Destination folders are an allow-list, never a free path.
// 1405-08-11 (user decision): workout programs no longer live on Drive. Their home is the Iran relay
// https://relay.hohe1.ir/workout/ — folder value "relay:workout". The cron joins the parts, checks md5, then sends the
// file to the relay's deploy/sync.php (v8 action "chunk", HMAC with RELAY_SECRET from secrets-extra.php) and merges
// workout/index.json + index.html there. Drive is not touched on this path.
const DOC_FOLDERS = [];                      // no Drive destination is open any more
const DOC_RELAY = ['relay:workout' => 'workout'];
const DOC_RELAY_NAME_RE = '/^[a-z0-9][a-z0-9._-]{0,150}\.(pdf|zip|gif|mp4|webm|png|jpe?g|webp)$/';
const DOC_RELAY_SUB_RE = '/^[a-z0-9][a-z0-9_-]{0,40}$/';
const RELAY_CHUNK = 2097152;                 // under the relay's 3MB per-request cap
const DOC_NAME_RE = '/^(?!\.)[^\/\\\\\x00-\x1f]{1,180}\.(pdf|zip|gif|mp4|webm|png|jpe?g|webp)$/u';
const DOC_SUB_RE = '/^[\p{L}\p{N}][\p{L}\p{N} ._-]{0,60}$/u';
const DOC_MAX_PARTS = 64;

function doc_dir(string $id): string {
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) throw new RuntimeException('bad upload_id (16 hex)');
    $d = apps_var('inbox/doc-' . $id);
    if (!is_dir($d)) mkdir($d, 0700, true);
    return $d;
}

function doc_check(string $folder, string $sub, string $name): void {
    if (isset(DOC_RELAY[$folder])) {
        if ($sub !== '' && !preg_match(DOC_RELAY_SUB_RE, $sub)) throw new RuntimeException('bad subfolder (a-z 0-9 _ -)');
        if (!preg_match(DOC_RELAY_NAME_RE, $name)) throw new RuntimeException('bad name for the relay (lowercase latin, media/pdf/zip)');
        return;
    }
    if (!in_array($folder, DOC_FOLDERS, true)) throw new RuntimeException('folder not allowed');
    if ($sub !== '' && !preg_match(DOC_SUB_RE, $sub)) throw new RuntimeException('bad subfolder');
    if (!preg_match(DOC_NAME_RE, $name)) throw new RuntimeException('bad name (no slash, media/pdf/zip extension)');
}

// The bytes must match the extension's family, not just the name.
function doc_mime_ok(string $path, string $name): void {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $want = ['pdf' => 'application/pdf', 'zip' => 'application/zip', 'gif' => 'image/', 'png' => 'image/', 'jpg' => 'image/',
        'jpeg' => 'image/', 'webp' => 'image/', 'mp4' => 'video/', 'webm' => 'video/'][$ext] ?? 'x';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    if (strpos($mime, $want) !== 0) throw new RuntimeException("content is $mime, not a .$ext file");
}

// POST multipart: upload_id=<16 hex> index=<0..> md5=<part md5> file=<part>
function job_doc_part(array $a): array {
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('no part (field "file", ≤32MB)');
    $i = (string) ($a['index'] ?? '');
    if (!preg_match('/^[0-9]{1,2}$/', $i) || (int) $i >= DOC_MAX_PARTS) throw new RuntimeException('bad index');
    if (!preg_match('/^[a-f0-9]{32}$/', (string) ($a['md5'] ?? '')) || md5_file($f['tmp_name']) !== $a['md5']) throw new RuntimeException('part md5 mismatch');
    $d = doc_dir((string) ($a['upload_id'] ?? ''));
    if (!move_uploaded_file($f['tmp_name'], "$d/" . str_pad($i, 2, '0', STR_PAD_LEFT))) throw new RuntimeException('could not hold the part');
    return ['held' => (int) $i, 'bytes' => filesize("$d/" . str_pad($i, 2, '0', STR_PAD_LEFT))];
}

// POST: upload_id total name md5 folder [subfolder]
function job_doc_commit(array $a): array {
    $id = (string) ($a['upload_id'] ?? ''); $d = doc_dir($id);
    $total = (int) ($a['total'] ?? 0);
    if ($total < 1 || $total > DOC_MAX_PARTS) throw new RuntimeException('bad total');
    for ($i = 0; $i < $total; $i++) if (!is_file("$d/" . str_pad((string) $i, 2, '0', STR_PAD_LEFT))) throw new RuntimeException("part $i missing");
    $name = (string) ($a['name'] ?? ''); $folder = (string) ($a['folder'] ?? ''); $sub = (string) ($a['subfolder'] ?? '');
    doc_check($folder, $sub, $name);
    if (!preg_match('/^[a-f0-9]{32}$/', (string) ($a['md5'] ?? ''))) throw new RuntimeException('md5 required');
    $label = mb_substr(trim((string) ($a['label'] ?? '')), 0, 200);     // Persian display name on the relay index
    $group = mb_substr(trim((string) ($a['group'] ?? '')), 0, 120);
    return job_enqueue('doc_send', ['upload_id' => $id, 'total' => $total, 'name' => $name, 'md5' => $a['md5'],
        'folder' => $folder, 'subfolder' => $sub, 'label' => $label, 'group' => $group]);
}

function run_doc_send(array $a): array {
    $d = doc_dir((string) $a['upload_id']); $name = (string) $a['name'];
    doc_check((string) $a['folder'], (string) $a['subfolder'], $name);
    $joined = "$d/joined";
    try {
        $out = fopen($joined, 'wb');
        for ($i = 0; $i < (int) $a['total']; $i++) {
            $in = fopen("$d/" . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'rb');
            if (!$in) { fclose($out); throw new RuntimeException("part $i is gone"); }
            stream_copy_to_stream($in, $out); fclose($in);
        }
        fclose($out);
        $md5 = md5_file($joined);
        if ($md5 !== $a['md5']) throw new RuntimeException('joined md5 mismatch');
        doc_mime_ok($joined, $name);
        if (isset(DOC_RELAY[(string) $a['folder']])) return relay_publish($joined, $a, $md5);
        $folder = drive_ensure_folder((string) $a['folder'], drive_root_id());
        if ($a['subfolder'] !== '') $folder = drive_ensure_folder((string) $a['subfolder'], $folder);
        $ex = drive_find($folder, $name);
        if ($ex && ($ex['md5Checksum'] ?? '') === $md5) {
            $r = $ex; $skipped = true;
        } else {
            $r = drive_upload_file($joined, $name, $folder, (new finfo(FILEINFO_MIME_TYPE))->file($joined) ?: 'application/octet-stream');
            $skipped = false;
            // Same name, other bytes: the old copy goes to Drive's trash (recoverable) so the folder holds one current file.
            if ($ex) drive_call('PATCH', '/files/' . $ex['id'], ['supportsAllDrives' => 'true'], ['trashed' => true]);
        }
        return ['name' => $name, 'id' => $r['id'], 'size' => (string) filesize($joined), 'webViewLink' => $r['webViewLink'] ?? '',
            'md5' => $md5, 'verified' => ($r['md5Checksum'] ?? '') === $md5, 'skipped_existing' => $skipped,
            'folder_id' => $folder, 'folder_link' => 'https://drive.google.com/drive/folders/' . $folder];
    } finally {
        foreach (glob("$d/*") ?: [] as $x) @unlink($x);
        @rmdir($d);
    }
}

// ---------------------------------------------------------------- Iran relay (relay.hohe1.ir)
function relay_call(array $payload): array {
    $secret = apps_secret('RELAY_SECRET');
    if ($secret === '') throw new RuntimeException('RELAY_SECRET missing in secrets-extra.php');
    $url = apps_secret('RELAY_URL') ?: 'https://relay.hohe1.ir/deploy/sync.php';
    $payload['ts'] = time();
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: apps-host',
        'X-Signature: sha256=' . hash_hmac('sha256', $body, $secret)]]);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) return ['ok' => false, 'error' => $err !== '' ? "network: $err" : "http-$code"];
    return $j;
}

function relay_send_file(string $path, string $repo): string {
    $size = filesize($path); $sha = hash_file('sha256', $path);
    $fh = fopen($path, 'rb'); $off = 0; $tries = 0;
    try {
        while ($off < $size) {
            fseek($fh, $off); $data = fread($fh, RELAY_CHUNK);
            $r = relay_call(['action' => 'chunk', 'repo' => $repo, 'offset' => $off, 'total' => $size, 'sha256' => $sha,
                'b64' => base64_encode($data)]);
            if (!empty($r['ok'])) { $off = (int) ($r['have'] ?? $off + strlen($data)); $tries = 0; if (!empty($r['done'])) return $sha; continue; }
            if (($r['error'] ?? '') === 'offset-mismatch' && is_int($r['have'] ?? null) && $r['have'] % RELAY_CHUNK === 0 && $r['have'] <= $size) $off = $r['have'];
            if (++$tries > 4) throw new RuntimeException('relay chunk failed at ' . $off . ': ' . ($r['error'] ?? '?'));
            sleep(2 ** $tries);
        }
    } finally { fclose($fh); }
    throw new RuntimeException('relay ended without done');
}

function relay_index_html(string $title, array $rows): string {
    $groups = [];
    foreach ($rows as $r) $groups[$r['folder'] ?? ''][] = $r;
    ksort($groups);
    $h = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
    $human = fn($n) => $n >= 1048576 ? sprintf('%.1f مگابایت', $n / 1048576) : max(1, intdiv((int) $n, 1024)) . ' کیلوبایت';
    $blocks = '';
    foreach ($groups as $g => $items) {
        usort($items, fn($a, $b) => strcmp($a['name'], $b['name']));
        $li = '';
        foreach ($items as $r) $li .= '<li><a href="' . $h($r['href']) . '">' . $h($r['name']) . '</a><span>' . $human((int) $r['bytes']) . '</span></li>';
        $blocks .= '<section>' . ($g !== '' ? '<h2>' . $h($g) . '</h2>' : '') . '<ul>' . $li . '</ul></section>';
    }
    return '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
        . '<title>' . $h($title) . '</title><style>body{margin:0;background:#f6f5f2;color:#222;line-height:1.8}'
        . 'main{width:100%;max-width:760px;margin:0 auto;padding:16px;box-sizing:border-box}h2{margin-top:1.5rem}'
        . 'ul{list-style:none;padding:0;margin:0}li{display:flex;justify-content:space-between;gap:12px;background:#fff;'
        . 'border-radius:10px;padding:10px 14px;margin:8px 0}a{color:#1a5fb4;text-decoration:none;overflow-wrap:anywhere;min-width:0}'
        . 'span{color:#777;white-space:nowrap}</style></head><body><main><h1>' . $h($title) . '</h1>' . $blocks . '</main></body></html>';
}

// Merge one row into <dest>/index.json on the relay (read back with snapshot) and rewrite index.html from it.
function relay_index_add(string $dest, array $row): void {
    $repo = "relay/public_html/$dest/index.json";
    $snap = relay_call(['action' => 'snapshot', 'files' => [$repo]]);
    $cur = [];
    $f = $snap['results'][0] ?? [];
    if (!empty($f['ok']) && isset($f['b64'])) $cur = json_decode((string) base64_decode($f['b64']), true) ?: [];
    elseif (($f['error'] ?? '') !== 'not-found') throw new RuntimeException('relay index unreadable: ' . ($f['error'] ?? $snap['error'] ?? '?'));
    $rows = array_values(array_filter($cur['rows'] ?? [], fn($r) => ($r['href'] ?? '') !== $row['href']));
    $rows[] = $row;
    $title = $cur['title'] ?? 'برنامه‌های تمرین';
    $json = json_encode(['title' => $title, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $html = relay_index_html($title, $rows);
    $files = [];
    foreach (["relay/public_html/$dest/index.json" => $json, "relay/public_html/$dest/index.html" => $html] as $rp => $c)
        $files[] = ['repo' => $rp, 'b64' => base64_encode($c), 'sha256' => hash('sha256', $c)];
    $r = relay_call(['action' => 'deploy', 'files' => $files]);
    if (empty($r['ok'])) throw new RuntimeException('relay index write failed: ' . json_encode($r['results'] ?? $r['error'] ?? '?'));
}

// Secret-free path (user choice 1405-08-11, option 2): the file waits in the outbox here, public_html/outbox.php lists and
// serves it, and the relay's public_html/intake/pull.php (no secret, fixed source URL) pulls, checks sha256, files it under
// workout/ and merges index.json/index.html itself. We only poke pull.php and read back what it says it filed.
const RELAY_PULL_URL = 'https://relay.hohe1.ir/intake/pull.php';

function outbox_dir(): string { $d = apps_var('outbox'); if (!is_dir($d)) mkdir($d, 0700, true); return $d; }
function outbox_list(): array { return json_decode((string) @file_get_contents(outbox_dir() . '/list.json'), true) ?: []; }
function outbox_save(array $l): void { $p = outbox_dir() . '/list.json'; file_put_contents("$p.tmp", json_encode(array_values($l), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); rename("$p.tmp", $p); }

function relay_pull_poke(): array {
    $ch = curl_init(apps_secret('RELAY_PULL_URL') ?: RELAY_PULL_URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 900, CURLOPT_HTTPHEADER => ['User-Agent: apps-host']]);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) ? $j : ['ok' => false, 'error' => $err !== '' ? "network: $err" : "http-$code", 'body' => substr((string) $raw, 0, 200)];
}

function relay_publish(string $joined, array $a, string $md5): array {
    $dest = DOC_RELAY[(string) $a['folder']]; $sub = (string) $a['subfolder']; $name = (string) $a['name'];
    $href = ($sub !== '' ? "$sub/" : '') . $name;
    $bytes = filesize($joined); $sha = hash_file('sha256', $joined);
    $row = ['name' => ($a['label'] ?? '') !== '' ? $a['label'] : $name, 'folder' => (string) ($a['group'] ?? ''),
        'href' => $href, 'bytes' => $bytes, 'sha256' => $sha];
    if (apps_secret('RELAY_SECRET') !== '') {           // push path, only when the shared secret was placed by hand
        relay_send_file($joined, "relay/public_html/$dest/$href");
        relay_index_add($dest, $row);
    } else {
        if (!copy($joined, outbox_dir() . "/$sha")) throw new RuntimeException('outbox write failed');
        $l = array_filter(outbox_list(), fn($r) => ($r['href'] ?? '') !== $href || ($r['dest'] ?? '') !== $dest);
        $l[] = $row + ['dest' => $dest, 'at' => time()];
        outbox_save($l);
        $r = relay_pull_poke();
        $filed = array_column($r['done'] ?? [], 'sha256', 'href');
        if (($filed[$href] ?? '') !== $sha) throw new RuntimeException('relay did not file ' . $href . ': ' . json_encode($r['failed'] ?? $r['error'] ?? $r, JSON_UNESCAPED_UNICODE));
        outbox_save(array_filter(outbox_list(), fn($x) => ($x['sha256'] ?? '') !== $sha));
        @unlink(outbox_dir() . "/$sha");
    }
    $url = 'https://relay.hohe1.ir/' . $dest . '/' . $href;
    return ['name' => $name, 'label' => $a['label'] ?? '', 'size' => (string) $bytes, 'webViewLink' => $url, 'url' => $url,
        'md5' => $md5, 'sha256' => $sha, 'verified' => true, 'folder_link' => 'https://relay.hohe1.ir/' . $dest . '/',
        'home' => 'relay'];
}

// ---------------------------------------------------------------- Iran relay: read one Iranian page (1405-08-11)
// Orchestra github-native-doors, section D. The session reaches this door with its GitHub pass; the relay is not reachable
// from the session and holds no shared secret with this host. So: the task waits here (public_html/relay-tasks.php lists
// it), the relay's intake/tasks.php pulls it, fetches the page itself (its own allow-list + daily cap per domain), and
// writes the answer on ITS OWN disk at tasks-out/<out>.json; we read it back from relay.hohe1.ir. Integrity comes from
// that last step: only the relay can write to relay.hohe1.ir. Nothing here is secret — the pages are public Iranian sites.
const RELAY_TASKS_URL = 'https://relay.hohe1.ir/intake/tasks.php';
const RELAY_OUT_BASE = 'https://relay.hohe1.ir/tasks-out/';
const IRANIAN_COM = ['digikala.com', 'torob.com', 'aparat.com', 'behtarino.com', 'shahrint.com', 'royasho.com',
    'digistyle.com', 'basalam.com', 'sheypoor.com', 'filimo.com'];   // same list as the relay's run.php

function relay_host_ok(string $host): bool {
    $host = strtolower($host);
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) return false;
    if (substr($host, -3) === '.ir') return true;
    foreach (IRANIAN_COM as $d) if ($host === $d || substr($host, -strlen($d) - 1) === ".$d") return true;
    return false;
}
function relay_tasks_dir(): string { $d = apps_var('relay-tasks'); if (!is_dir($d)) mkdir($d, 0700, true); return $d; }
function relay_tasks_list(): array {
    $out = [];
    foreach (glob(relay_tasks_dir() . '/*.json') ?: [] as $p) {
        $t = json_decode((string) file_get_contents($p), true);
        if (is_array($t) && ($t['at'] ?? 0) > time() - 3600) $out[] = ['id' => $t['id'], 'url' => $t['url'], 'out' => $t['out']];
        elseif (filemtime($p) < time() - 3600) @unlink($p);
    }
    return $out;
}
function relay_get(string $url, int $timeout): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['User-Agent: apps-host']]);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return [$code, is_string($raw) ? $raw : '', $err];
}
function run_relay_fetch(array $a): array {
    $url = trim((string) ($a['url'] ?? ''));
    $u = parse_url($url);
    if (!is_array($u) || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || !relay_host_ok((string) ($u['host'] ?? '')))
        throw new RuntimeException('url must be http(s) on an Iranian domain (.ir or the relay list)');
    if (strlen($url) > 2000) throw new RuntimeException('url too long');
    $t = ['id' => bin2hex(random_bytes(16)), 'url' => $url, 'out' => bin2hex(random_bytes(16)), 'at' => time()];
    $p = relay_tasks_dir() . '/' . $t['id'] . '.json';
    file_put_contents($p, json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    try {
        [$c, $raw, $e] = relay_get(apps_secret('RELAY_TASKS_URL') ?: RELAY_TASKS_URL, 240);
        $poke = json_decode($raw, true);
        if ($c !== 200 || !is_array($poke)) throw new RuntimeException('relay tasks.php: ' . ($e !== '' ? "network: $e" : "http-$c " . substr($raw, 0, 160)));
        [$c2, $raw2, $e2] = relay_get((apps_secret('RELAY_OUT_BASE') ?: RELAY_OUT_BASE) . $t['out'] . '.json', 60);
        $r = json_decode($raw2, true);
        if ($c2 !== 200 || !is_array($r) || ($r['id'] ?? '') !== $t['id']) throw new RuntimeException('relay answer missing (http-' . $c2 . '): ' . json_encode($poke['failed'] ?? $poke, JSON_UNESCAPED_UNICODE));
        return $r;
    } finally {
        @unlink($p);
    }
}

// ---------------------------------------------------------------- queue (cron-driven, no web time limit)
const QUEUED = ['relay_fetch'];            // only relay_fetch is queued straight from the door (GitHub-pass or key)
const INTERNAL = ['app_send', 'doc_send'];  // only enqueued by the host itself

function queue_dir(): string { $d = apps_var('queue'); if (!is_dir($d)) mkdir($d, 0700, true); return $d; }

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
        apps_log('queue', basename($p) . ' ' . $q['job'] . ' ' . $q['state']);
        return basename($p);
    }
    return null;
}

function queue_cleanup(): void {
    foreach (glob(queue_dir() . '/*.json') ?: [] as $p) if (filemtime($p) < time() - 14 * 86400) unlink($p);
    foreach (glob(apps_var('inbox') . '/*') ?: [] as $t) if (filemtime($t) < time() - 86400) is_dir($t) ? apps_rrmdir($t) : unlink($t);   // doc-<id>/ parts of an abandoned upload
}
