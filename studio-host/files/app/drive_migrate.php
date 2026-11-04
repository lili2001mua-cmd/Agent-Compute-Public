<?php
// Copy the whole main-account My-Drive into a connected extra account (BACKUP), server side, resumable.
// Owner of the procedure: drive-steward (private repo). Nothing is deleted from the source.
//
//   door: job=drive_migrate_start account=BACKUP   → shares the source root (reader, no email) with the account,
//                                                    creates the destination root, seeds the work queue
//         job=drive_migrate_status                 → counts, errors, folder id map (old → new)
//         job=drive_vault_save account=BACKUP      → copies var/drive-web-client.json into <account>/_Vault/
//   tick: drive_migrate_step(budget)               → works the queue for up to `budget` seconds each minute
//
// Folders are recreated; files are copied with files.copy by the destination account (no download through
// the host). Binary files are checked by md5. Shortcuts are listed for later, not copied.
require_once __DIR__ . '/drive_accounts.php';

const DM_STATE = 'drive-migrate.json';
const DM_SHORTCUT = 'application/vnd.google-apps.shortcut';

function dm_load(): array {
    $s = @json_decode((string) @file_get_contents(studio_var(DM_STATE)), true);
    return is_array($s) ? $s : [];
}

function dm_save(array $s): void { da_write(studio_var(DM_STATE), $s); }

// Destination-account call with a cached token (da_token refreshes on every call).
function dm_dst(string $acc, string $method, string $path, array $query = [], $json = null, int $timeout = 120): array {
    static $tok = [];
    if (!isset($tok[$acc]) || $tok[$acc][1] < time() + 60) $tok[$acc] = [da_token($acc), time() + 3000];
    $url = DRIVE_API . $path . ($query ? '?' . http_build_query($query) : '');
    for ($try = 0; ; $try++) {
        $h = ['Authorization: Bearer ' . $tok[$acc][0]];
        if ($json !== null) $h[] = 'Content-Type: application/json';
        [$code, $r] = studio_http($method, $url, $h, $json !== null ? json_encode($json) : null, $timeout);
        if ($code === 401 && $try === 0) { $tok[$acc] = [da_token($acc), time() + 3000]; continue; }
        $rate = $code === 403 && is_array($r) && preg_match('/rateLimitExceeded|userRateLimitExceeded/', json_encode($r));
        if ($try >= 4 || !($code === 429 || $code >= 500 || $rate)) break;
        sleep(min(16, 2 ** $try));
    }
    if ($code >= 400) throw new RuntimeException("dst $method $path → $code " . substr(is_array($r) ? json_encode($r) : (string) $r, 0, 300));
    return is_array($r) ? $r : [];
}

function dm_account_email(string $acc): string {
    $a = @json_decode((string) @file_get_contents(studio_var('drive-accounts') . '/' . da_name($acc) . '.json'), true);
    $e = (string) ($a['email'] ?? '');
    if ($e === '') throw new RuntimeException("account $acc has no recorded email");
    return $e;
}

function job_drive_migrate_start(array $a): array {
    $acc = da_name((string) ($a['account'] ?? ''));
    $s = dm_load();
    if (!empty($s) && ($s['stage'] ?? '') !== 'done' && empty($a['restart'])) return ['already' => true] + dm_summary($s);
    $root = drive_root_id();
    $src = drive_call('GET', "/files/$root", ['fields' => 'id,name,mimeType']);
    // Reader access for the destination account, without an email notification.
    drive_call('POST', "/files/$root/permissions", ['sendNotificationEmail' => 'false', 'fields' => 'id'],
        ['type' => 'user', 'role' => 'reader', 'emailAddress' => dm_account_email($acc)]);
    $dst = dm_dst($acc, 'POST', '/files', ['fields' => 'id'], ['name' => $src['name'], 'mimeType' => FOLDER_MIME, 'parents' => ['root']]);
    $s = ['account' => $acc, 'stage' => 'copying', 'started' => gmdate('c'), 'src_root' => $root, 'dst_root' => $dst['id'],
        'map' => [$root => $dst['id']], 'folders' => [$root => $src['name']],
        'queue' => [['id' => $root, 'kind' => 'list', 'dst' => $dst['id'], 'path' => $src['name']]],
        'files' => 0, 'bytes' => 0, 'md5_ok' => 0, 'md5_bad' => [], 'errors' => [], 'shortcuts' => []];
    dm_save($s);
    studio_log('drive-migrate', "start → $acc");
    return dm_summary($s);
}

function dm_summary(array $s): array {
    return ['stage' => $s['stage'] ?? 'none', 'account' => $s['account'] ?? '', 'started' => $s['started'] ?? '',
        'finished' => $s['finished'] ?? '', 'queue_left' => count($s['queue'] ?? []), 'folders' => count($s['folders'] ?? []),
        'files' => $s['files'] ?? 0, 'bytes' => $s['bytes'] ?? 0, 'md5_ok' => $s['md5_ok'] ?? 0,
        'md5_bad' => count($s['md5_bad'] ?? []), 'errors' => count($s['errors'] ?? []), 'shortcuts' => count($s['shortcuts'] ?? []),
        'dst_root' => $s['dst_root'] ?? ''];
}

function job_drive_migrate_status(array $a): array {
    $s = dm_load();
    $out = dm_summary($s);
    if (!empty($a['detail'])) {
        $fm = [];
        foreach ($s['folders'] ?? [] as $old => $path) $fm[] = ['path' => $path, 'old' => $old, 'new' => $s['map'][$old] ?? ''];
        $out += ['folder_map' => $fm, 'error_list' => array_slice($s['errors'] ?? [], 0, 50),
            'md5_bad_list' => array_slice($s['md5_bad'] ?? [], 0, 50), 'shortcut_list' => array_slice($s['shortcuts'] ?? [], 0, 50)];
    }
    if (!empty($a['file_map'])) $out['map'] = $s['map'] ?? [];
    return $out;
}

// One unit of work. Returns false when the queue is empty.
function dm_work_one(array &$s): bool {
    if (empty($s['queue'])) return false;
    $it = array_shift($s['queue']);
    $acc = $s['account'];
    try {
        if ($it['kind'] === 'list') {
            $page = null;
            do {
                $q = ['q' => "'" . $it['id'] . "' in parents and trashed=false", 'pageSize' => 1000, 'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true', 'fields' => 'nextPageToken,files(id,name,mimeType,size,md5Checksum)'];
                if ($page) $q['pageToken'] = $page;
                $r = drive_call('GET', '/files', $q);
                foreach ($r['files'] ?? [] as $f) {
                    $path = $it['path'] . '/' . $f['name'];
                    if ($f['mimeType'] === FOLDER_MIME) $s['queue'][] = ['id' => $f['id'], 'kind' => 'folder', 'name' => $f['name'], 'dst' => $it['dst'], 'path' => $path];
                    elseif ($f['mimeType'] === DM_SHORTCUT) $s['shortcuts'][] = ['id' => $f['id'], 'path' => $path];
                    else $s['queue'][] = ['id' => $f['id'], 'kind' => 'file', 'name' => $f['name'], 'dst' => $it['dst'], 'path' => $path,
                        'md5' => $f['md5Checksum'] ?? '', 'size' => (int) ($f['size'] ?? 0)];
                }
                $page = $r['nextPageToken'] ?? null;
            } while ($page);
        } elseif ($it['kind'] === 'folder') {
            if (empty($s['map'][$it['id']])) {
                $d = dm_dst($acc, 'POST', '/files', ['fields' => 'id'], ['name' => $it['name'], 'mimeType' => FOLDER_MIME, 'parents' => [$it['dst']]]);
                $s['map'][$it['id']] = $d['id'];
            }
            $s['folders'][$it['id']] = $it['path'];
            $s['queue'][] = ['id' => $it['id'], 'kind' => 'list', 'dst' => $s['map'][$it['id']], 'path' => $it['path']];
        } else {
            if (empty($s['map'][$it['id']])) {
                $c = dm_dst($acc, 'POST', '/files/' . $it['id'] . '/copy', ['fields' => 'id,size,md5Checksum', 'supportsAllDrives' => 'true'],
                    ['name' => $it['name'], 'parents' => [$it['dst']]], 600);
                $s['map'][$it['id']] = $c['id'];
                $s['files']++;
                $s['bytes'] += (int) ($c['size'] ?? 0);
                if ($it['md5'] !== '') {
                    if (($c['md5Checksum'] ?? '') === $it['md5']) $s['md5_ok']++;
                    else $s['md5_bad'][] = ['path' => $it['path'], 'old' => $it['id'], 'new' => $c['id']];
                }
            }
        }
    } catch (Throwable $e) {
        $it['tries'] = ($it['tries'] ?? 0) + 1;
        if ($it['tries'] < 3) $s['queue'][] = $it;
        else $s['errors'][] = ['path' => $it['path'], 'id' => $it['id'], 'error' => substr($e->getMessage(), 0, 300)];
    }
    return true;
}

// Called by tick.php every minute while a migration is open.
function drive_migrate_step(int $budget): void {
    $s = dm_load();
    if (($s['stage'] ?? '') !== 'copying') return;
    $t0 = time(); $n = 0;
    while (time() - $t0 < $budget && dm_work_one($s)) { if (++$n % 10 === 0) dm_save($s); }
    if (empty($s['queue'])) { $s['stage'] = 'done'; $s['finished'] = gmdate('c'); studio_log('drive-migrate', 'done ' . json_encode(dm_summary($s))); }
    dm_save($s);
}

// The web-client JSON (client id + secret) also lives in the destination account, so a lost host can be rebuilt.
function job_drive_vault_save(array $a): array {
    $acc = da_name((string) ($a['account'] ?? ''));
    $c = da_client();
    if (!$c) throw new RuntimeException('web client not saved on host');
    $q = dm_dst($acc, 'GET', '/files', ['q' => "name='_Vault' and 'root' in parents and mimeType='" . FOLDER_MIME . "' and trashed=false", 'fields' => 'files(id)']);
    $vault = $q['files'][0]['id'] ?? dm_dst($acc, 'POST', '/files', ['fields' => 'id'], ['name' => '_Vault', 'mimeType' => FOLDER_MIME, 'parents' => ['root']])['id'];
    $body = json_encode(['web' => ['client_id' => $c['id'], 'client_secret' => $c['secret'], 'redirect_uris' => [da_redirect_uri()],
        'note' => 'studio-drive web client — restore with drive-login.php (first-time form)']], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $name = 'studio-drive-web-client.json';
    $old = dm_dst($acc, 'GET', '/files', ['q' => "name='$name' and '$vault' in parents and trashed=false", 'fields' => 'files(id)']);
    $bd = 'b' . bin2hex(random_bytes(8));
    $meta = empty($old['files']) ? ['name' => $name, 'parents' => [$vault], 'mimeType' => 'application/json'] : ['name' => $name];
    $mp = "--$bd\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . json_encode($meta) . "\r\n--$bd\r\nContent-Type: application/json\r\n\r\n$body\r\n--$bd--";
    $tok = da_token($acc);
    $url = DRIVE_UPLOAD . (empty($old['files']) ? '' : '/' . $old['files'][0]['id']) . '?uploadType=multipart&fields=id,md5Checksum,size';
    [$st, $r] = studio_http(empty($old['files']) ? 'POST' : 'PATCH', $url, ['Authorization: Bearer ' . $tok, "Content-Type: multipart/related; boundary=$bd"], $mp);
    if ($st >= 400 || empty($r['id'])) throw new RuntimeException("vault upload failed ($st)");
    return ['vault_folder' => $vault, 'file' => $r['id'], 'size' => $r['size'] ?? '', 'md5_match' => ($r['md5Checksum'] ?? '') === md5($body)];
}
