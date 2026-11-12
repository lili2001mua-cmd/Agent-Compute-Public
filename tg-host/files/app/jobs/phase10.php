<?php
// Phase 10 (B2) — the tg host pulls the night itself: Telegram → Drive, one bundle at a time, resumable.
// Notebook: Agent-Project-Backup Memory-Workflow/nightly-chain-independence/public-runner-notebook-1405-08-20.md, phase B
// (`live, limit:1`: one real bundle on Drive with an equal hash).
// Parity contract with the Python pull (intake-pull.yml → telegram_pool.py → pool_workspace/pool_media_backend.publish):
//   file name = "<msg id>+++" . safe_name(filename attr, else "<type>_<id>.mp4|.jpg|''")   telegram_pool.py main()
//   folder    = My-Drive/intake-pool/telegram/<key>/ under POOL_PARENT_FOLDER_ID          pool_media_backend.ensure_pool_path
//   upload    = skip when a same-name file has the same md5; a changed one is updated in place, never a second copy;
//               after upload Drive's md5Checksum must equal the local md5                       pool_media_backend.publish
//   a bundle with any media that failed to download is not pulled                               failed-download-marks-the-bundle-pulled
// Writes (phase7.php allowlist only): Memory-Workflow/intake-pool/state/telegram/<key>/host-pull.json (per bundle, the
// drive_files shape of status.json) and Memory-Workflow/nightly-pool/host/<date>/pull.json (the night's summary).
// ⛔ Never status.json (split writers), never a Telegram write, never a delete. Runs only in mode `live`
//    (tg_host_config, phase8.php); `limit` caps how many bundles a night starts (0 = all).
// Not counted in the chain's night byte cap (tg_night_bytes, 400 MB for `stage`): the host pull must never starve the
// running chain; it is bounded by TG_PULL_FILE_CAP, TG_DISK_CAP_BYTES and 2-5 s between downloads.
// No chain lease: the tick runs one job at a time under its flock, so the host session is never shared; Drive writes
// are idempotent by name+md5, so the Python pull of the same key skips what the host uploaded (and the reverse).

const TG_PULL_SLICE_S = 170;                     // work per job, then hand back (the tick budget is 240 s)
const TG_PULL_FILE_CAP = 200 * 1048576;
const TG_PULL_CHUNK = 8 * 1048576;               // Drive resumable chunk (multiple of 256 KiB), as pool_media_backend
const TG_PULL_MAX_FAILS = 4;
const TG_PULL_MAX_SLICES = 40;
const TG_PULL_RETRY_S = 180;
const TG_PULL_NIGHTS_BACK = 2;                   // never start pulling a night older than this
const TG_POOL_PARENT_ID = '1E0X_anS6bZtlPzL6hsWs2pN6cQrnHqkW'; // pool_media_backend.POOL_PARENT_FOLDER_ID (My-Drive)
const TG_DRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';

// ---- pure helpers (tested in tests/host-pull-test.php) ----------------------------------------------
/** The slice clock (the test moves it forward to cut an upload in the middle). */
function tg_pull_now(): int { return time() + (int) ($GLOBALS['tg_pull_skew'] ?? 0); }
/** telegram_pool.safe_name(): Unicode \w kept, the rest "_", spaces "_", extension kept when ≤ 8 chars. */
function tg_safe_name(?string $s, int $n = 70): string {
    $s = str_replace(' ', '_', trim((string) preg_replace('/[^\w\-. ]/u', '_', (string) $s)));
    if ($s === '') return 'x';
    $p = mb_strrpos($s, '.');
    if ($p === false || $p === 0 || mb_strlen($s) - $p - 1 > 8) return mb_substr($s, 0, $n);
    $stem = mb_substr($s, 0, $p); $ext = mb_substr($s, $p + 1);
    return mb_substr($stem, 0, max(1, $n - mb_strlen($ext) - 1)) . '.' . $ext;
}
/** The name the Python pull gives one media message. */
function tg_pull_name(array $m): string {
    $mt = tg_kind_group($m);
    $ext = $mt === 'video' ? '.mp4' : ($mt === 'photo' ? '.jpg' : '');
    $fname = tg_m_filename($m) ?? ($mt . '_' . (int) $m['id'] . $ext);
    return (int) $m['id'] . '+++' . tg_safe_name($fname);
}
/** Drive query literal. */
function tg_dq(string $s): string { return str_replace(["\\", "'"], ["\\\\", "\\'"], $s); }
/** "bytes=0-1048575" ⇒ next offset 1048576; no header ⇒ 0. */
function tg_range_next(?string $range): int {
    return ($range !== null && preg_match('/bytes=0-(\d+)/', $range, $m)) ? (int) $m[1] + 1 : 0;
}

// ---- state ------------------------------------------------------------------------------------------
function tg_pull_state(): array { return tg_read_json(tg_var('host-pull.json')) ?? []; }
function tg_pull_mark(string $date, string $key, array $patch): void {
    $fh = fopen(tg_var('host-pull.lock'), 'c'); flock($fh, LOCK_EX);
    try {
        $s = tg_pull_state();
        $s[$date][$key] = array_merge($s[$date][$key] ?? [], $patch, ['updated' => time()]);
        ksort($s);
        while (count($s) > 14) array_shift($s);
        tg_write_json(tg_var('host-pull.json'), $s);
    } finally { flock($fh, LOCK_UN); fclose($fh); }
}

// ---- tick ---------------------------------------------------------------------------------------------
/** Tick hook (CLI, no library): in mode live, enqueue the next bundle of the newest planned night. Never throws. */
function tg_host_pull_tick(?int $now = null): string {
    if (PHP_SAPI !== 'cli') return 'not-cli';
    $now ??= time();
    try {
        $cfg = tg_host_config();
        if ($cfg['mode'] !== 'live') return 'not-live';
        $target = tg_host_target_date($now);
        $plans = tg_host_plan_state();
        $date = null;
        foreach (array_reverse(array_keys($plans)) as $d) {
            if (($plans[$d]['state'] ?? '') === 'done' && is_array($plans[$d]['bundles'] ?? null)) { $date = $d; break; }
        }
        if ($date === null) return 'no-plan';
        if ((new DateTimeImmutable($date))->diff(new DateTimeImmutable($target))->days > TG_PULL_NIGHTS_BACK) return 'plan-too-old';
        $night = tg_pull_state()[$date] ?? [];
        foreach ($night as $k => $r) {
            if (($r['state'] ?? '') !== 'queued') continue;
            $st = tg_read_json(tg_job_dir((string) ($r['job'] ?? '')) . '/status.json') ?? [];
            if (in_array($st['state'] ?? '', ['queued', 'running'], true)) return 'busy';
            if (($st['state'] ?? '') !== 'done') {   // killed beyond the resume cap, or the job folder is gone
                $fails = (int) ($r['fails'] ?? 0) + 1;
                tg_pull_mark($date, $k, ['state' => 'failed', 'fails' => $fails, 'error' => (string) ($st['error'] ?? 'job lost'), 'next_at' => $now + TG_PULL_RETRY_S]);
            }
            $night = tg_pull_state()[$date] ?? [];
        }
        $started = count(array_filter($night, fn($r) => ($r['state'] ?? '') !== ''));
        foreach ($plans[$date]['bundles'] as $b) {
            $k = (string) $b['key']; $r = $night[$k] ?? [];
            $state = (string) ($r['state'] ?? '');
            if (in_array($state, ['done', 'gave_up'], true)) continue;
            if ($state === 'failed' && (int) ($r['fails'] ?? 0) >= TG_PULL_MAX_FAILS) {
                tg_pull_mark($date, $k, ['state' => 'gave_up']); tg_log('chain', 'host pull gave up ' . $k);
                tg_host_pull_summary($date);
                continue;
            }
            if ((int) ($r['slices'] ?? 0) >= TG_PULL_MAX_SLICES) { tg_pull_mark($date, $k, ['state' => 'gave_up', 'error' => 'slice cap']); continue; }
            if ($state === 'failed' && $now < (int) ($r['next_at'] ?? 0)) return 'backoff';
            if ($state === '' && $cfg['limit'] > 0 && $started >= $cfg['limit']) return 'limit';
            if (count(glob(tg_var('queue') . '/*.json') ?: []) >= TG_QUEUE_CAP) return 'queue-full';
            $id = tg_id();
            tg_status($id, ['id' => $id, 'job' => 'host_pull', 'state' => 'queued', 'created_at' => $now, 'role' => 'internal']);
            tg_write_json(tg_dir(tg_var('queue')) . '/' . $now . '-host_pull-' . $id . '.json',
                ['id' => $id, 'job' => 'host_pull', 'args' => ['date' => $date, 'key' => $k], 'role' => 'internal', 'attempt' => 0, 'not_before' => 0]);
            tg_pull_mark($date, $k, ['state' => 'queued', 'job' => $id, 'slices' => (int) ($r['slices'] ?? 0) + 1]);
            return 'queued';
        }
        return 'night-done';
    } catch (Throwable $e) {
        tg_log('chain', 'host pull tick error ' . get_class($e));
        return 'error';
    }
}

// ---- Drive (BACKUP account through hohe_backup_drive, phase6.php) -------------------------------------
/** [code, body(array|string), headers(lowercase name => value)] */
function tg_drive_http(string $method, string $url, $body = null, array $hdr = []): array {
    [$tok] = hohe_backup_drive(TG_BASE);
    $h = []; $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $tok], $hdr),
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$h) { $p = strpos($line, ':'); if ($p) $h[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1)); return strlen($line); }]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string) $raw, true);
    return [$code, is_array($j) ? $j : (string) $raw, $h];
}
function tg_drive_list(string $q, string $fields): array {
    $u = 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $q, 'fields' => 'files(' . $fields . ')',
        'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'pageSize' => 10]);
    for ($i = 0; $i < 4; $i++) {
        [$c, $b] = tg_drive_http('GET', $u);
        if ($c === 200) return $b['files'] ?? [];
        if (!in_array($c, [429, 500, 502, 503, 504], true)) break;
        sleep(2 ** $i);
    }
    throw new RuntimeException('DRIVE_LIST ' . $c);
}
function tg_drive_folder(string $name, string $parent): string {
    $hit = tg_drive_list("name='" . tg_dq($name) . "' and '" . tg_dq($parent) . "' in parents and mimeType='" . TG_DRIVE_FOLDER_MIME . "' and trashed=false", 'id');
    if ($hit) return (string) $hit[0]['id'];
    [$c, $b] = tg_drive_http('POST', 'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&fields=id',
        json_encode(['name' => $name, 'mimeType' => TG_DRIVE_FOLDER_MIME, 'parents' => [$parent]]), ['Content-Type: application/json; charset=UTF-8']);
    if ($c !== 200 || empty($b['id'])) throw new RuntimeException('DRIVE_MKDIR ' . $c);
    return (string) $b['id'];
}
function tg_drive_pool_folder(string $key): string {
    return tg_drive_folder($key, tg_drive_folder('telegram', tg_drive_folder('intake-pool', TG_POOL_PARENT_ID)));
}

/** Resumable upload with a session kept in $sessFile. Returns Drive's file JSON, or null when $deadline passed. */
function tg_drive_upload(string $path, string $name, string $folder, ?string $existingId, string $sessFile, int $deadline): ?array {
    $total = filesize($path);
    $s = tg_read_json($sessFile);
    $off = 0;
    $fields = 'fields=id,name,md5Checksum,webViewLink,size&supportsAllDrives=true';
    if (!$s || (int) ($s['bytes'] ?? -1) !== $total || (int) ($s['at'] ?? 0) < time() - 6 * 86400) {
        [$url, $meta, $m] = $existingId
            ? ['https://www.googleapis.com/upload/drive/v3/files/' . rawurlencode($existingId) . '?uploadType=resumable&' . $fields, [], 'PATCH']
            : ['https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&' . $fields, ['name' => $name, 'parents' => [$folder]], 'POST'];
        [$c, , $h] = tg_drive_http($m, $url, json_encode((object) $meta),
            ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: application/octet-stream', 'X-Upload-Content-Length: ' . $total]);
        if ($c !== 200 || empty($h['location'])) throw new RuntimeException('DRIVE_UPLOAD_INIT ' . $c);
        $s = ['uri' => $h['location'], 'bytes' => $total, 'at' => time()];
        tg_write_json($sessFile, $s);
    } else {
        [$c, $b, $h] = tg_drive_http('PUT', $s['uri'], '', ['Content-Range: bytes */' . $total, 'Content-Length: 0']);
        if ($c === 200 || $c === 201) return is_array($b) ? $b : throw new RuntimeException('DRIVE_UPLOAD_BODY');
        if (in_array($c, [404, 410], true)) { @unlink($sessFile); throw new RuntimeException('DRIVE_UPLOAD_SESSION_GONE'); }
        if ($c !== 308) throw new RuntimeException('DRIVE_UPLOAD_STATUS ' . $c);
        $off = tg_range_next($h['range'] ?? null);
    }
    $fh = fopen($path, 'rb'); $tries = 0;
    try {
        while (true) {
            if (tg_pull_now() > $deadline) return null;
            $len = min(TG_PULL_CHUNK, $total - $off);
            fseek($fh, $off); $chunk = $len > 0 ? fread($fh, $len) : '';
            [$c, $b, $h] = tg_drive_http('PUT', $s['uri'], $chunk,
                ['Content-Length: ' . strlen($chunk), 'Content-Range: bytes ' . ($len > 0 ? $off . '-' . ($off + $len - 1) : '*') . '/' . $total]);
            if ($c === 200 || $c === 201) { @unlink($sessFile); return is_array($b) ? $b : throw new RuntimeException('DRIVE_UPLOAD_BODY'); }
            if ($c === 308) { $off = tg_range_next($h['range'] ?? null); $tries = 0; continue; }
            if (in_array($c, [404, 410], true)) { @unlink($sessFile); throw new RuntimeException('DRIVE_UPLOAD_SESSION_GONE'); }
            if (!in_array($c, [0, 429, 500, 502, 503, 504], true) || ++$tries > 4) throw new RuntimeException('DRIVE_UPLOAD_CHUNK ' . $c);
            sleep(2 ** $tries);
            [$c, , $h] = tg_drive_http('PUT', $s['uri'], '', ['Content-Range: bytes */' . $total, 'Content-Length: 0']);
            if ($c === 308) $off = tg_range_next($h['range'] ?? null);
        }
    } finally { fclose($fh); }
}

// ---- job ------------------------------------------------------------------------------------------------
/** Internal job (no door role): pull one planned bundle, one time slice at a time. */
function job_host_pull(array $a, callable $hb, string $role = 'read'): array {
    if ($role !== 'internal') throw new RuntimeException('internal job');
    $date = (string) ($a['date'] ?? ''); $key = (string) ($a['key'] ?? '');
    try {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^b[0-9]{1,12}$/', $key)) throw new RuntimeException('bad args');
        $res = tg_host_pull_slice($date, $key, $hb, tg_pull_now() + TG_PULL_SLICE_S);
    } catch (Throwable $e) {
        $r = tg_pull_state()[$date][$key] ?? [];
        tg_pull_mark($date, $key, ['state' => 'failed', 'fails' => (int) ($r['fails'] ?? 0) + 1,
            'error' => get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 120), 'next_at' => time() + TG_PULL_RETRY_S]);
        tg_log('chain', 'host pull failed ' . $key . ' ' . get_class($e));
        throw $e;
    }
    tg_pull_mark($date, $key, ['state' => $res['done'] ? 'done' : 'partial'] + ($res['done'] ? ['files' => count($res['files'] ?? [])] : []));
    if ($res['done']) { tg_log('chain', 'host pull done ' . $key); tg_host_pull_summary($date); }
    return $res;
}

function tg_host_pull_slice(string $date, string $key, callable $hb, int $deadline): array {
    $plan = tg_host_plan_state()[$date] ?? [];
    $b = null; foreach ((array) ($plan['bundles'] ?? []) as $x) if (($x['key'] ?? '') === $key) $b = $x;
    if (!$b) throw new RuntimeException('NO_PLAN_BUNDLE');
    $peer = (int) ($plan['peer'] ?? 0) ?: tg_host_plan_source();
    if (!$peer) throw new RuntimeException('NO_SOURCE_CHAT');
    $dir = tg_dir(tg_var('pull/' . $key));
    $mf = $dir . '/pull.json';
    $man = tg_read_json($mf) ?? ['key' => $key, 'files' => []];

    // 1) download every media message not on disk yet (one library load)
    $need = array_values(array_filter(array_map('intval', $b['media_ids']), fn($id) => empty($man['files'][(string) $id]['local']) && empty($man['files'][(string) $id]['drive_id']) && empty($man['files'][(string) $id]['skipped'])));
    if ($need) {
        tg_mp_with(tg_session_dir(), function ($api) use ($peer, $need, $dir, $mf, &$man, $hb, $deadline) {
            foreach (tg_get_msgs($api, $peer, $need) as $i => $m) {
                $id = (string) $need[$i];
                if (tg_pull_now() > $deadline) return ['partial' => true];
                if (!$m) throw new RuntimeException('MESSAGE_GONE ' . $id);
                $doc = tg_m_document($m); $photo = $doc ? null : tg_m_photo($m);
                if (!$doc && !$photo) { $man['files'][$id] = ['message_id' => (int) $id, 'type' => tg_kind_group($m), 'skipped' => 'no downloadable media']; tg_write_json($mf, $man); continue; }
                $size = $doc ? (int) ($doc['size'] ?? 0) : 0;
                if ($size > TG_PULL_FILE_CAP) throw new RuntimeException('FILE_TOO_BIG ' . $id);
                if ($size && tg_usage_bytes() + $size > TG_DISK_CAP_BYTES) throw new RuntimeException('DISK_CAP');
                if ($i) usleep(random_int(2000000, 5000000));   // 2-5 s between downloads (ک19)
                $part = $dir . '/' . $id . '.part';
                @unlink($part);
                $api->downloadToFile($doc ? ['_' => 'messageMediaDocument', 'document' => $doc] : ['_' => 'messageMediaPhoto', 'photo' => $photo], $part);
                $bytes = (int) filesize($part);
                if ($bytes <= 0 || ($size && $bytes !== $size)) { @unlink($part); throw new RuntimeException('DOWNLOAD_SHORT ' . $id); }
                rename($part, $dir . '/' . $id . '.bin');
                $man['files'][$id] = ['message_id' => (int) $id, 'type' => tg_kind_group($m), 'rel_path' => tg_pull_name($m),
                    'size' => $bytes, 'md5' => md5_file($dir . '/' . $id . '.bin'), 'local' => true];
                tg_write_json($mf, $man);
                $hb(['phase' => 'download', 'key' => $man['key'], 'msg' => (int) $id]);
            }
            return ['partial' => false];
        }, 600);
        foreach ($need as $id) if (empty($man['files'][(string) $id])) return ['done' => false, 'phase' => 'download'];
    }

    // 2) upload what is on disk (no library)
    $man['folder_id'] ??= tg_drive_pool_folder($key);
    tg_write_json($mf, $man);
    foreach ($man['files'] as $id => &$f) {
        if (empty($f['local'])) continue;
        if (tg_pull_now() > $deadline) { unset($f); tg_write_json($mf, $man); return ['done' => false, 'phase' => 'upload']; }
        $bin = $dir . '/' . $id . '.bin';
        $hit = tg_drive_list("name='" . tg_dq($f['rel_path']) . "' and '" . tg_dq($man['folder_id']) . "' in parents and trashed=false", 'id,md5Checksum,webViewLink');
        if ($hit && ($hit[0]['md5Checksum'] ?? '') === $f['md5']) {
            $f += ['drive_id' => (string) $hit[0]['id'], 'web_view_link' => (string) ($hit[0]['webViewLink'] ?? ''), 'skipped_existing' => true];
        } else {
            $up = tg_drive_upload($bin, $f['rel_path'], $man['folder_id'], $hit ? (string) $hit[0]['id'] : null, $dir . '/' . $id . '.upload.json', $deadline);
            if ($up === null) { unset($f); tg_write_json($mf, $man); return ['done' => false, 'phase' => 'upload']; }
            if (($up['md5Checksum'] ?? '') !== $f['md5']) throw new RuntimeException('MD5_MISMATCH_AFTER_UPLOAD ' . $id);
            $f += ['drive_id' => (string) $up['id'], 'web_view_link' => (string) ($up['webViewLink'] ?? ''), 'skipped_existing' => false];
        }
        unset($f['local']); @unlink($bin);
        tg_write_json($mf, $man);
        $hb(['phase' => 'upload', 'key' => $key, 'msg' => (int) $id]);
    }
    unset($f);

    // 3) the per-bundle record on main, then the local copy goes
    $rec = tg_host_pull_record($date, $key, $b, (int) $peer, $man);
    $r = tg_repo_put('Memory-Workflow/intake-pool/state/telegram/' . $key . '/host-pull.json',
        json_encode($rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        'tg-host: pulled ' . $key . ' (' . count($rec['drive_files']['uploaded']) . ' files, night ' . $date . ')');
    if (empty($r['ok'])) throw new RuntimeException('repo write failed code=' . (int) ($r['code'] ?? 0));
    tg_rm_r($dir);
    return ['done' => true, 'files' => $rec['drive_files']['uploaded']];
}

/** Pure: the record, in the drive_files shape of status.json (rel_path, drive_id, md5Checksum, web_view_link, skipped_existing). */
function tg_host_pull_record(string $date, string $key, array $b, int $peer, array $man): array {
    $up = [];
    foreach (array_map('intval', $b['media_ids']) as $id) {
        $f = $man['files'][(string) $id] ?? null;
        if (!$f || empty($f['drive_id'])) continue;
        $up[] = ['rel_path' => $f['rel_path'], 'drive_id' => $f['drive_id'], 'md5Checksum' => $f['md5'], 'web_view_link' => $f['web_view_link'],
                 'skipped_existing' => (bool) $f['skipped_existing'], 'size' => (int) $f['size'], 'message_id' => $id, 'type' => $f['type']];
    }
    $types = array_values(array_unique(array_map(fn($u) => $u['type'], $up)));
    return ['key' => $key, 'night' => $date, 'source' => 'telegram', 'writer' => 'tg-host', 'chat_id' => $peer,
            'source_url' => $b['source_url'] ?? null, 'source_message_ids' => array_map('intval', $b['media_ids']),
            'media_kind' => count($types) === 1 ? $types[0] : (count($types) ? 'mixed' : 'none'),
            'drive_folder_id' => (string) ($man['folder_id'] ?? ''),
            'drive_files' => ['uploaded' => $up, 'failed' => []],
            'skipped' => array_values(array_filter(array_map(fn($f) => isset($f['skipped']) ? ['message_id' => $f['message_id'], 'type' => $f['type'], 'why' => $f['skipped']] : null, $man['files']))),
            'pulled_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'host_version' => (string) ((tg_read_json(TG_BASE . '/var/deployed.json') ?? [])['version'] ?? '')];
}

/** The night's summary on main (best effort; never throws). */
function tg_host_pull_summary(string $date): void {
    try {
        $night = tg_pull_state()[$date] ?? [];
        $keys = array_map(fn($b) => (string) $b['key'], (array) (tg_host_plan_state()[$date]['bundles'] ?? []));
        $rows = [];
        foreach ($keys as $k) $rows[$k] = ['state' => (string) ($night[$k]['state'] ?? 'not-started')] + (isset($night[$k]['error']) ? ['error' => $night[$k]['error']] : []);
        $doc = ['date' => $date, 'mode' => tg_host_config()['mode'], 'limit' => tg_host_config()['limit'], 'keys' => $rows,
                'done' => count(array_filter($rows, fn($r) => $r['state'] === 'done')), 'at_utc' => gmdate('Y-m-d\TH:i:s\Z')];
        tg_repo_put('Memory-Workflow/nightly-pool/host/' . $date . '/pull.json',
            json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 'tg-host: pull summary ' . $date);
    } catch (Throwable $e) { tg_log('chain', 'host pull summary error ' . get_class($e)); }
}
