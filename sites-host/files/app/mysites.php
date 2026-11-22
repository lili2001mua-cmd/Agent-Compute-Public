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

const MYS_FULL_MODE = 'weekly';        // STY-02 گامِ ۱۰۹: کاملِ هفتگی (جمعه پس از ۰۳:۰۰ تهران یا گذشتِ ۷ روز)؛ در صورتِ ناسالم‌بودنِ افزایشی خودکار به روزانه برمی‌گردد
const MYS_INC_HEALTH_MAX_AGE = 21600;  // حداکثر ۶ ساعت از آخرین بکاپِ افزایشیِ موفق برای مجازبودنِ حالتِ هفتگی

function mys_full_due(array $prevFull, array $prevInc, int $nowUtc, int $tehTs): bool {
    $day = gmdate('Y-m-d', $tehTs);
    if (($prevFull['day'] ?? '') === $day && !empty($prevFull['ok'])) return false;
    if (MYS_FULL_MODE !== 'weekly') return true;

    // قفلِ ایمنی: اگر بکاپِ افزایشیِ ۱۵دقیقه‌ای هنوز فعال یا سالم نباشد (بیش از ۶ ساعت از آخرین موفق)، خودکار روزانه می‌گیرد
    $incOk = !empty($prevInc['ok']);
    $incAge = $nowUtc - (strtotime((string) ($prevInc['utc'] ?? '')) ?: 0);
    if (!$incOk || $incAge > MYS_INC_HEALTH_MAX_AGE) return true;

    // در حالتِ سالمِ افزایشی: فقط جمعه‌ها (w == 5) یا اگر بیش از ۷ روز از آخرین کاملِ موفق گذشته باشد
    $lastFullUtc = strtotime((string) ($prevFull['utc'] ?? '')) ?: 0;
    if ($lastFullUtc <= 0 || ($nowUtc - $lastFullUtc) >= 7 * 86400) return true;
    return (int) gmdate('w', $tehTs) === 5;
}

function dh_mysites_daily(): void {
    $now = time();
    $teh = $now + 12600;                                      // Asia/Tehran, UTC+3:30
    if ((int) gmdate('G', $teh) < 3) return;                  // after 03:00 Tehran
    $day = gmdate('Y-m-d', $teh);
    $st = dh_state()['mysites'] ?? [];
    $stInc = dh_state()['mysites_inc'] ?? [];
    $out = [];
    foreach (MYS_DB_ENVS as $name => $envs) {
        $site = dh_sites()[$name] ?? null;
        if (!$site) continue;
        foreach ($envs as $env) {
            $k = "$name/$env";
            $prev = $st[$k] ?? [];
            $prevInc = $stInc[$k] ?? [];
            if (!mys_full_due($prev, $prevInc, $now, $teh)) { $out[$k] = $prev; continue; }
            if (($prev['day'] ?? '') === $day && (($prev['tries'] ?? 0) >= 6 || $now - strtotime($prev['utc'] ?? '') < 1800)) { $out[$k] = $prev; continue; }   // a failed day retries every 30 min, up to 6 times
            try { $out[$k] = ['day' => $day, 'ok' => true, 'utc' => gmdate('c'), 'mode' => MYS_FULL_MODE] + mys_backup_db($name, $site, $env); }
            catch (Throwable $e) { $out[$k] = ['day' => $day, 'ok' => false, 'utc' => gmdate('c'), 'tries' => (($prev['day'] ?? '') === $day ? ($prev['tries'] ?? 0) : 0) + 1,
                                                'error' => substr($e->getMessage(), 0, 200)]; }
            dh_log("mysites $k " . ($out[$k]['ok'] ? 'ok' : 'FAIL ' . $out[$k]['error']));
        }
    }
    if ($out) dh_state_set('mysites', $out);
}

/* ---------- STY-02 (گامِ ۱۰۷): بکاپِ افزایشیِ ۱۵دقیقه‌ای از change_log روی گوگل‌درایو ---------- */
const MYS_INC_INTERVAL = 900;      // هر ۱۵ دقیقه یک بار
const MYS_INC_PAGE = 500;          // اندازهٔ هر صفحهٔ کوئری روی sync.php
const MYS_INC_MAX_ROWS = 5000;     // سقفِ ردیف در هر بستهٔ ۱۵دقیقه‌ای
const MYS_INC_SAFE_AGE_SEC = 5;    // حاشیهٔ ۵ ثانیه‌ایِ خواندنِ امن (بی‌شکاف)

function mys_inc_build_ndjson(string $site, string $env, array $rows, string $prevSha, string $takenAt): array {
    $seqFrom = (int) $rows[0]['seq'];
    $seqTo   = (int) $rows[count($rows) - 1]['seq'];
    $meta = [
        '_meta'       => 'sty02-inc/v1',
        'site'        => $site,
        'env'         => $env,
        'seq_from'    => $seqFrom,
        'seq_to'      => $seqTo,
        'row_count'   => count($rows),
        'prev_sha256' => $prevSha,
        'taken_at'    => $takenAt,
    ];
    $buf = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    foreach ($rows as $r) {
        $buf .= json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    return ['ndjson' => $buf, 'seq_from' => $seqFrom, 'seq_to' => $seqTo, 'row_count' => count($rows)];
}

function mys_inc_query(array $site, string $env, string $sql): array {
    [$code, $j, $err] = dh_post($site, $env, ['action' => 'query', 'ref' => 'main', 'sql' => $sql, 'files' => []]);
    if ($code !== 200 || !is_array($j) || empty($j['ok'])) {
        throw new RuntimeException('inc query: ' . ($err ?: ($j['error'] ?? "HTTP $code")));
    }
    return $j['rows'] ?? [];
}

function mys_backup_incremental(string $name, array $site, string $env, array $prev): array {
    $afterSeq = (int) ($prev['last_seq'] ?? 0);
    $prevSha  = (string) ($prev['last_sha256'] ?? 'GENESIS');

    // سقفِ پیوستهٔ امن (contiguous safe watermark): اولین سطرِ جوان‌تر از ۵ ثانیه مرزِ توقف است
    $wmRows = mys_inc_query(
        $site,
        $env,
        "SELECT COALESCE((SELECT MIN(seq) - 1 FROM change_log WHERE seq > $afterSeq AND created_at > (NOW(6) - INTERVAL " . MYS_INC_SAFE_AGE_SEC . " SECOND)), (SELECT COALESCE(MAX(seq), 0) FROM change_log)) AS wm"
    );
    $watermark = (int) ($wmRows[0]['wm'] ?? 0);
    if ($watermark <= $afterSeq) {
        return ['env' => $env, 'last_seq' => $afterSeq, 'last_sha256' => $prevSha, 'rows' => 0, 'skipped' => 'no_changes'];
    }

    // دریافتِ صفحه‌بندی‌شدهٔ سطرهای پیوسته تا سقفِ MYS_INC_MAX_ROWS
    $rows = [];
    $cur = $afterSeq;
    while (count($rows) < MYS_INC_MAX_ROWS && $cur < $watermark) {
        $lim = min(MYS_INC_PAGE, MYS_INC_MAX_ROWS - count($rows));
        $page = mys_inc_query(
            $site,
            $env,
            "SELECT seq, tx_id, table_name, pk_val, op, company_id, before_json, after_json, created_at FROM change_log WHERE seq > $cur AND seq <= $watermark ORDER BY seq ASC LIMIT $lim"
        );
        if (!$page) break;
        foreach ($page as $r) {
            $rows[] = $r;
            $cur = (int) $r['seq'];
        }
        if (count($page) < $lim) break;
    }
    if (!$rows) {
        return ['env' => $env, 'last_seq' => $afterSeq, 'last_sha256' => $prevSha, 'rows' => 0, 'skipped' => 'no_rows'];
    }

    $pk = mys_pubkey();
    $taken = gmdate('Y-m-d\TH:i:s\Z');
    $built = mys_inc_build_ndjson($name, $env, $rows, $prevSha, $taken);
    $tok = bin2hex(random_bytes(12));
    $gzPath  = dh_var('dumps') . "/inc-$tok.ndjson.gz";
    $encPath = dh_var('dumps') . "/inc-$tok.mys";
    try {
        $gz = gzencode($built['ndjson'], 6);
        if ($gz === false) throw new RuntimeException('inc gzencode failed');
        file_put_contents($gzPath, $gz, LOCK_EX);
        $plainSha = hash_file('sha256', $gzPath);
        mys_encrypt($gzPath, $encPath, $pk['pk']);
        $encSha = hash_file('sha256', $encPath);
        $md5    = md5_file($encPath);
        $size   = filesize($encPath);

        $folder = mys_folder(mys_folder(MYS_SITE_FOLDERS[$name], 'db-inc', "$name/db-inc"), $env, "$name/db-inc/$env");
        $fname  = sprintf('%s-%s-inc-%s-s%d-%d.ndjson.gz.enc', $name, $env, gmdate('Ymd-His'), $built['seq_from'], $built['seq_to']);
        $ap = [
            'site'         => $name,
            'kind'         => 'db-inc',
            'env'          => $env,
            'seq_from'     => (string) $built['seq_from'],
            'seq_to'       => (string) $built['seq_to'],
            'row_count'    => (string) $built['row_count'],
            'prev_sha256'  => $prevSha,
            'taken_at'     => $taken,
            'sha256_plain' => $plainSha,
            'sha256_enc'   => $encSha,
            'key_id'       => $pk['id'],
            'owner'        => 'drive-steward',
        ];
        $r = mys_upload($encPath, $fname, $folder, $ap);
        if ((int) ($r['size'] ?? -1) !== $size || ($r['md5Checksum'] ?? '') !== $md5) {
            throw new RuntimeException('Drive inc copy differs (size/md5)');
        }
        mys_index_append(MYS_SITE_FOLDERS[$name], [
            'name'         => $fname,
            'id'           => $r['id'],
            'kind'         => 'db-inc',
            'env'          => $env,
            'seq_from'     => $built['seq_from'],
            'seq_to'       => $built['seq_to'],
            'row_count'    => $built['row_count'],
            'prev_sha256'  => $prevSha,
            'size'         => $size,
            'sha256_plain' => $plainSha,
            'sha256_enc'   => $encSha,
            'key_id'       => $pk['id'],
            'taken_at'     => $taken,
            'md5_verified' => true,
        ]);
    } finally {
        @unlink($gzPath);
        @unlink($encPath);
    }

    // ⛔ هرسِ خودکارِ فایل‌های افزایشی ممنوع (قیدِ بی‌حذفِ کارفرما ۱۴۰۵/۰۸/۳۰)
    return [
        'env'         => $env,
        'file_id'     => $r['id'],
        'name'        => $fname,
        'bytes'       => $size,
        'rows'        => $built['row_count'],
        'seq_from'    => $built['seq_from'],
        'seq_to'      => $built['seq_to'],
        'last_seq'    => $built['seq_to'],
        'last_sha256' => $encSha,
        'more_behind' => $built['seq_to'] < $watermark,
    ];
}

function dh_mysites_incremental(): void {
    $st = dh_state()['mysites_inc'] ?? [];
    $out = [];
    $changed = false;
    foreach (MYS_DB_ENVS as $name => $envs) {
        $site = dh_sites()[$name] ?? null;
        if (!$site) continue;
        foreach ($envs as $env) {
            $k = "$name/$env";
            $prev = $st[$k] ?? [];
            $lastRun = strtotime((string) ($prev['utc'] ?? '')) ?: 0;
            $interval = !empty($prev['more_behind']) ? 60 : MYS_INC_INTERVAL;   // در عقب‌ماندگیِ بالای ۵۰۰۰ سطر، هر دقیقه ادامه می‌دهد
            if ($lastRun > 0 && (time() - $lastRun) < $interval) {
                $out[$k] = $prev;
                continue;
            }
            $changed = true;
            try {
                $res = mys_backup_incremental($name, $site, $env, $prev);
                $out[$k] = ['ok' => true, 'utc' => gmdate('c')] + $res;
            } catch (Throwable $e) {
                $out[$k] = [
                    'ok'          => false,
                    'utc'         => gmdate('c'),
                    'last_seq'    => (int) ($prev['last_seq'] ?? 0),
                    'last_sha256' => (string) ($prev['last_sha256'] ?? 'GENESIS'),
                    'error'       => substr($e->getMessage(), 0, 200),
                ];
            }
            dh_log("mysites-inc $k " . ($out[$k]['ok'] ? 'ok rows=' . ($out[$k]['rows'] ?? 0) : 'FAIL ' . $out[$k]['error']));
        }
    }
    if ($changed && $out) dh_state_set('mysites_inc', $out);
}

/* ---------- STY-02 (گامِ ۱۱۰): گزارشِ خودکارِ ماهانهٔ مانور و سلامتِ زنجیرهٔ بکاپ ---------- */
function mys_monthly_drill_evaluate(array $indexFiles, int $liveMaxSeq, int $nowUtc): array {
    $fulls = array_values(array_filter($indexFiles, fn($f) => ($f['kind'] ?? '') === 'db'));
    $incs  = array_values(array_filter($indexFiles, fn($f) => ($f['kind'] ?? '') === 'db-inc'));
    usort($fulls, fn($a, $b) => strcmp((string) ($a['taken_at'] ?? ''), (string) ($b['taken_at'] ?? '')));
    usort($incs,  fn($a, $b) => ((int) ($a['seq_from'] ?? 0)) <=> ((int) ($b['seq_from'] ?? 0)));

    $lastFull = $fulls ? $fulls[count($fulls) - 1] : null;
    $lastInc  = $incs  ? $incs[count($incs) - 1]   : null;

    $fullAgeH = $lastFull ? round(($nowUtc - (strtotime((string) ($lastFull['taken_at'] ?? '')) ?: 0)) / 3600, 1) : null;
    $incAgeH  = $lastInc  ? round(($nowUtc - (strtotime((string) ($lastInc['taken_at'] ?? '')) ?: 0)) / 3600, 1)  : null;

    $chainBreaks = 0;
    $seqGaps     = 0;
    $unverified  = 0;
    $totalIncBytes = 0;
    $totalIncRows  = 0;
    for ($i = 0; $i < count($incs); $i++) {
        $cur = $incs[$i];
        $totalIncBytes += (int) ($cur['size'] ?? 0);
        $totalIncRows  += (int) ($cur['row_count'] ?? 0);
        if (empty($cur['md5_verified'])) $unverified++;
        if ($i > 0) {
            $prev = $incs[$i - 1];
            if (($cur['prev_sha256'] ?? '') !== ($prev['sha256_enc'] ?? '')) $chainBreaks++;
            if ((int) ($cur['seq_from'] ?? 0) !== ((int) ($prev['seq_to'] ?? 0) + 1)) $seqGaps++;
        }
    }

    $lastIncSeq = $lastInc ? (int) ($lastInc['seq_to'] ?? 0) : 0;
    $seqLag     = max(0, $liveMaxSeq - $lastIncSeq);

    $alerts = [];
    if (!$lastFull) $alerts[] = 'هیچ بکاپِ کاملی در INDEX.json ثبت نشده است';
    elseif ($fullAgeH > 192) $alerts[] = "آخرین بکاپِ کامل کهنه است ({$fullAgeH} ساعت > ۸ روز)";
    if (!$lastInc) $alerts[] = 'هیچ بستهٔ افزایشی در INDEX.json ثبت نشده است';
    elseif ($incAgeH > 6) $alerts[] = "آخرین بستهٔ افزایشی کهنه است ({$incAgeH} ساعت > ۶ ساعت)";
    if ($chainBreaks > 0) $alerts[] = "گسستِ زنجیرهٔ هش: {$chainBreaks} مورد";
    if ($seqGaps > 0) $alerts[] = "شکافِ شمارهٔ ترتیبی (seq): {$seqGaps} مورد";
    if ($unverified > 0) $alerts[] = "بستهٔ بدونِ تأییدِ MD5: {$unverified} مورد";

    return [
        'ok'              => count($alerts) === 0,
        'verdict'         => count($alerts) === 0 ? 'سالم' : 'نیاز به بررسی',
        'full_count'      => count($fulls),
        'inc_count'       => count($incs),
        'total_inc_rows'  => $totalIncRows,
        'total_inc_bytes' => $totalIncBytes,
        'last_full_age_h' => $fullAgeH,
        'last_inc_age_h'  => $incAgeH,
        'live_max_seq'    => $liveMaxSeq,
        'last_inc_seq'    => $lastIncSeq,
        'seq_lag'         => $seqLag,
        'chain_breaks'    => $chainBreaks,
        'seq_gaps'        => $seqGaps,
        'unverified'      => $unverified,
        'alerts'          => $alerts,
    ];
}

function dh_mysites_monthly_drill(): void {
    $now = time();
    $teh = $now + 12600;
    if ((int) gmdate('j', $teh) !== 1 || (int) gmdate('G', $teh) < 4) return;   // روزِ اولِ هر ماه پس از ۰۴:۰۰ تهران
    $month = gmdate('Y-m', $teh);
    $st = dh_state()['mysites_drill'] ?? [];
    $out = [];
    $changed = false;
    foreach (MYS_DB_ENVS as $name => $envs) {
        $site = dh_sites()[$name] ?? null;
        if (!$site) continue;
        foreach ($envs as $env) {
            $k = "$name/$env";
            $prev = $st[$k] ?? [];
            if (($prev['month'] ?? '') === $month && !empty($prev['ok'])) { $out[$k] = $prev; continue; }
            $changed = true;
            try {
                $got = mys_q("'" . MYS_SITE_FOLDERS[$name] . "' in parents and trashed=false and name='INDEX.json'", 'files(id)');
                $idxFiles = [];
                if ($got) {
                    [$c, $raw] = mys_drive('GET', 'https://www.googleapis.com/drive/v3/files/' . $got[0]['id'] . '?alt=media');
                    if ($c === 200 && is_array($j = json_decode($raw, true))) $idxFiles = $j['files'] ?? [];
                }
                $liveRows = mys_inc_query($site, $env, "SELECT COALESCE(MAX(seq), 0) AS max_seq FROM change_log");
                $liveMax  = (int) ($liveRows[0]['max_seq'] ?? 0);
                $ev = mys_monthly_drill_evaluate($idxFiles, $liveMax, $now);
                $out[$k] = ['month' => $month, 'utc' => gmdate('c')] + $ev;

                $md = "# گزارشِ خودکارِ ماهانهٔ مانورِ بکاپ — `$name/$env` ($month)\n\n"
                    . "- **حکم:** {$ev['verdict']}\n"
                    . "- **بکاپِ کامل:** {$ev['full_count']} نسخه (سنِ آخرین نسخه: {$ev['last_full_age_h']} ساعت)\n"
                    . "- **بکاپِ افزایشیِ ۱۵دقیقه‌ای:** {$ev['inc_count']} بسته · {$ev['total_inc_rows']} سطر · " . round($ev['total_inc_bytes'] / 1024, 1) . " KB (سنِ آخرین بسته: {$ev['last_inc_age_h']} ساعت)\n"
                    . "- **سلامتِ زنجیره:** گسستِ هش = `{$ev['chain_breaks']}` · شکافِ seq = `{$ev['seq_gaps']}` · بی‌تأییدِ MD5 = `{$ev['unverified']}` · عقب‌ماندگی = `{$ev['seq_lag']}` سطر\n"
                    . ($ev['alerts'] ? "- **هشدارها:** " . implode(' · ', $ev['alerts']) . "\n" : "- **هشدار:** هیچ\n");
                dh_gh_put($site['repo'], "deploy-results/backup-drill-$month.md", $md, "chore(STY-02): monthly backup drill report $month ($name/$env)");
            } catch (Throwable $e) {
                $out[$k] = ['month' => $month, 'ok' => false, 'utc' => gmdate('c'), 'error' => substr($e->getMessage(), 0, 200)];
            }
            dh_log("mysites-drill $k " . ($out[$k]['ok'] ? 'ok' : 'ALERT'));
        }
    }
    if ($changed && $out) dh_state_set('mysites_drill', $out);
}
