<?php
// Named jobs of apps.hohedoc.ir. Each replaces a private-repo GitHub Actions workflow (user decision 1405-08-07,
// orchestration app-build-zero-actions in the private repo):
//   app-release-publish.yml + workout-apk-to-drive.yml → app_upload (inline) → app_send (queued)
//   workout-pdf-to-drive.yml                           → doc_part × n + doc_commit (inline) → doc_send (queued)
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
const DOC_FOLDERS = ['برنامه‌های تمرین'];
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
    return job_enqueue('doc_send', ['upload_id' => $id, 'total' => $total, 'name' => $name, 'md5' => $a['md5'],
        'folder' => $folder, 'subfolder' => $sub]);
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

// ---------------------------------------------------------------- queue (cron-driven, no web time limit)
const QUEUED = [];                         // nothing is queued straight from the door
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
