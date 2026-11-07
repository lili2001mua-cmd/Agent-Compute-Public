<?php
// Daily database backup of each site straight to My-Sites on the BACKUP Drive — never to git.
// Once per Tehran day (after 03:00) it takes the same dump as the dbdump action, encrypts it with the studio host's
// PUBLIC key (format MYS2, same as studio-host/files/app/my_sites.php and Sys.scripts/my_sites_upload.py of
// Agent-Project-Backup), uploads it to My-Sites/<site>/db/<env>/ with appProperties, checks Drive's md5 of the
// encrypted file, appends a row to the site's INDEX.json, deletes the local copy, and prunes old dumps
// (keep 7 daily + 4 weekly + 12 monthly). This host can encrypt but never decrypt: only the studio host holds the
// private key. Result per site in status.php under `mysites`.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/editbus.php';   // hohe_backup_drive(): the BACKUP token from the studio host's var/

const MYS_SITE_FOLDERS = ['stylistcrm' => '14jyX35hw6auN3wtJymNTBqNQ1_2i5sSy'];   // My-Sites/<site>/ (drive-tree.md)
const MYS_DB_ENVS = ['stylistcrm' => ['pwa']];
const MYS_CHUNK = 1048576;
const MYS_UP_CHUNK = 8 * 1048576;
const MYS_KEEP = ['daily' => 7, 'weekly' => 4, 'monthly' => 12];

function mys_pubkey(): array {
    $p = DH_ROOT . '/../studio.hohedoc.ir/var/my-sites-pub.json';
    $k = @json_decode((string) @file_get_contents($p), true);
    if (!is_array($k) || empty($k['pk']) || empty($k['id'])) throw new RuntimeException('no My-Sites public key from the studio host');
    return $k;
}

// MYS2: "MYS2" | u16 wrapped length | RSA-OAEP(SHA-1) of a random 32-byte key | u32 chunk size | AES-256-GCM chunks + tag.
function mys_encrypt(string $src, string $dst, string $pem): void {
    $key = random_bytes(32);
    if (!openssl_public_encrypt($key, $wrapped, $pem, OPENSSL_PKCS1_OAEP_PADDING)) throw new RuntimeException('cannot wrap the key');
    $in = fopen($src, 'rb'); $out = fopen($dst, 'wb');
    fwrite($out, 'MYS2' . pack('n', strlen($wrapped)) . $wrapped . pack('N', MYS_CHUNK));
    $cur = (string) fread($in, MYS_CHUNK); $i = 0;
    while (true) {
        $next = strlen($cur) === MYS_CHUNK ? (string) fread($in, MYS_CHUNK) : '';
        $last = ($next === '');
        $ct = openssl_encrypt($cur, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, "\0\0\0\0" . pack('J', $i), $tag, $last ? "\x01" : "\x00", 16);
        if ($ct === false) throw new RuntimeException('encrypt failed');
        fwrite($out, $ct . $tag);
        if ($last) break;
        $cur = $next; $i++;
    }
    fclose($in); fclose($out);
}

function mys_drive(string $method, string $url, ?string $body = null, array $h = []): array {
    $tok = hohe_backup_drive(DH_ROOT)[0];
    [$c, $raw] = dh_http($method, $url, array_merge(["Authorization: Bearer $tok"], $h), $body, 120);
    return [$c, (string) $raw];
}

function mys_q(string $q, string $fields = 'files(id,name,appProperties)'): array {
    [$c, $raw] = mys_drive('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q' => $q, 'fields' => $fields, 'pageSize' => 1000]));
    if ($c !== 200) throw new RuntimeException("drive list HTTP $c");
    return json_decode($raw, true)['files'] ?? [];
}

// Same folder tags as my_sites_upload.py (appProperties.my_sites_folder), so both writers find the same folder.
function mys_folder(string $parent, string $name, string $tag): string {
    $got = mys_q("'$parent' in parents and trashed=false and mimeType='application/vnd.google-apps.folder' and appProperties has { key='my_sites_folder' and value='$tag' }", 'files(id)');
    if ($got) return $got[0]['id'];
    [$c, $raw] = mys_drive('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', json_encode(['name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$parent], 'appProperties' => ['my_sites_folder' => $tag, 'owner' => 'drive-steward']]),
        ['Content-Type: application/json']);
    if ($c !== 200) throw new RuntimeException("drive mkdir HTTP $c");
    return json_decode($raw, true)['id'];
}

function mys_upload(string $path, string $name, string $parent, array $ap): array {
    $size = filesize($path);
    $tok = hohe_backup_drive(DH_ROOT)[0];
    $loc = '';
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,size,md5Checksum');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => json_encode(['name' => $name, 'parents' => [$parent], 'appProperties' => $ap]),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $tok", 'Content-Type: application/json; charset=UTF-8', "X-Upload-Content-Length: $size"],
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$loc) { if (stripos($l, 'location:') === 0) $loc = trim(substr($l, 9)); return strlen($l); }]);
    curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || $loc === '') throw new RuntimeException("resumable start HTTP $code");
    $fh = fopen($path, 'rb'); $off = 0; $res = null;
    while ($off < $size || $size === 0) {
        $buf = (string) fread($fh, MYS_UP_CHUNK); $end = $off + strlen($buf) - 1;
        $range = $size === 0 ? 'bytes */0' : "bytes $off-$end/$size";
        [$c, $raw] = dh_http('PUT', $loc, ["Content-Range: $range"], $buf, 300);
        if ($c === 200 || $c === 201) { $res = json_decode($raw, true); break; }
        if ($c !== 308) { fclose($fh); throw new RuntimeException("upload chunk HTTP $c"); }
        $off = $end + 1;
    }
    fclose($fh);
    if (!$res) throw new RuntimeException('upload ended without a file');
    return $res;
}

function mys_index_append(string $siteFolder, array $row): void {
    $got = mys_q("'$siteFolder' in parents and trashed=false and name='INDEX.json'", 'files(id)');
    $idx = ['files' => []];
    if ($got) {
        [$c, $raw] = mys_drive('GET', 'https://www.googleapis.com/drive/v3/files/' . $got[0]['id'] . '?alt=media');
        if ($c === 200 && is_array($j = json_decode($raw, true))) $idx = $j;
    }
    $idx['files'][] = $row; $idx['updated'] = $row['taken_at'];
    $body = json_encode($idx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($got) [$c] = mys_drive('PATCH', 'https://www.googleapis.com/upload/drive/v3/files/' . $got[0]['id'] . '?uploadType=media', $body, ['Content-Type: application/json']);
    else {
        $tmp = dh_var('mys-index.json'); file_put_contents($tmp, $body);
        mys_upload($tmp, 'INDEX.json', $siteFolder, ['my_sites_index' => '1', 'owner' => 'drive-steward']); @unlink($tmp); $c = 200;
    }
    if ($c !== 200) throw new RuntimeException("INDEX.json HTTP $c");
}

// Which dated files survive: newest per day for 7 days, per ISO week for 4 weeks, per month for 12 months.
function mys_keep(array $files): array {
    usort($files, fn($a, $b) => strcmp($b['appProperties']['taken_at'] ?? '', $a['appProperties']['taken_at'] ?? ''));
    $keep = []; $seen = ['daily' => [], 'weekly' => [], 'monthly' => []];
    foreach ($files as $f) {
        $t = strtotime($f['appProperties']['taken_at'] ?? '') ?: 0;
        foreach (['daily' => 'Y-m-d', 'weekly' => 'o-W', 'monthly' => 'Y-m'] as $k => $fmt) {
            $b = gmdate($fmt, $t);
            if (!isset($seen[$k][$b]) && count($seen[$k]) < MYS_KEEP[$k]) { $seen[$k][$b] = 1; $keep[$f['id']] = 1; }
        }
    }
    return $keep;
}

function mys_backup_db(string $name, array $site, string $env): array {
    $pk = mys_pubkey();
    $d = dh_dbdump($site, $env, 'mysites');
    if (empty($d['ok'])) throw new RuntimeException('dbdump: ' . ($d['error'] ?? 'failed'));
    if (!preg_match('/[?&]t=([a-f0-9]{40})$/', (string) $d['download'], $m)) throw new RuntimeException('dbdump: no local token');
    $tok = $m[1];
    $gz = dh_var('dumps') . "/$tok.gz";
    $enc = dh_var('dumps') . "/$tok.mys";
    try {
        $plain = hash_file('sha256', $gz);
        mys_encrypt($gz, $enc, $pk['pk']);
        $encSha = hash_file('sha256', $enc); $md5 = md5_file($enc); $size = filesize($enc);
        $taken = gmdate('Y-m-d\TH:i:s\Z');
        $folder = mys_folder(mys_folder(MYS_SITE_FOLDERS[$name], 'db', "$name/db"), $env, "$name/db/$env");
        $fname = sprintf('%s-%s-%s.sql.gz.enc', $name, $env, gmdate('Ymd-His'));
        $ap = ['site' => $name, 'kind' => 'db', 'env' => $env, 'taken_at' => $taken, 'sha256_plain' => $plain,
               'sha256_enc' => $encSha, 'key_id' => $pk['id'], 'owner' => 'drive-steward'];
        $r = mys_upload($enc, $fname, $folder, $ap);
        if ((int) ($r['size'] ?? -1) !== $size || ($r['md5Checksum'] ?? '') !== $md5) throw new RuntimeException('Drive copy differs (size/md5)');
        mys_index_append(MYS_SITE_FOLDERS[$name], ['name' => $fname, 'id' => $r['id'], 'kind' => 'db', 'env' => $env, 'size' => $size,
            'sha256_plain' => $plain, 'sha256_enc' => $encSha, 'key_id' => $pk['id'], 'taken_at' => $taken, 'md5_verified' => true]);
    } finally { @unlink($gz); @unlink($enc); @unlink(dh_var('dumps') . "/$tok.json"); }
    $pruned = 0;
    $all = mys_q("'$folder' in parents and trashed=false and appProperties has { key='kind' and value='db' }");
    $keep = mys_keep($all);
    foreach ($all as $f) if (!isset($keep[$f['id']])) { [$c] = mys_drive('DELETE', 'https://www.googleapis.com/drive/v3/files/' . $f['id']); if ($c === 204) $pruned++; }
    return ['env' => $env, 'file_id' => $r['id'], 'bytes' => $size, 'tables' => $d['tables'] ?? null, 'kept' => count($keep), 'pruned' => $pruned];
}

function dh_mysites_daily(): void {
    $teh = time() + 12600;                                    // Asia/Tehran, UTC+3:30
    if ((int) gmdate('G', $teh) < 3) return;                 // after 03:00 Tehran, once a day
    $day = gmdate('Y-m-d', $teh);
    $st = dh_state()['mysites'] ?? [];
    $out = [];
    foreach (MYS_DB_ENVS as $name => $envs) {
        $site = dh_sites()[$name] ?? null;
        if (!$site) continue;
        foreach ($envs as $env) {
            $k = "$name/$env";
            $prev = $st[$k] ?? [];
            if (($prev['day'] ?? '') === $day && !empty($prev['ok'])) { $out[$k] = $prev; continue; }
            if (($prev['day'] ?? '') === $day && (($prev['tries'] ?? 0) >= 6 || time() - strtotime($prev['utc'] ?? '') < 1800)) { $out[$k] = $prev; continue; }   // a failed day retries every 30 min, up to 6 times
            try { $out[$k] = ['day' => $day, 'ok' => true, 'utc' => gmdate('c')] + mys_backup_db($name, $site, $env); }
            catch (Throwable $e) { $out[$k] = ['day' => $day, 'ok' => false, 'utc' => gmdate('c'), 'tries' => (($prev['day'] ?? '') === $day ? ($prev['tries'] ?? 0) : 0) + 1,
                                                'error' => substr($e->getMessage(), 0, 200)]; }
            dh_log("mysites $k " . ($out[$k]['ok'] ? 'ok' : 'FAIL ' . $out[$k]['error']));
        }
    }
    if ($out) dh_state_set('mysites', $out);
}
