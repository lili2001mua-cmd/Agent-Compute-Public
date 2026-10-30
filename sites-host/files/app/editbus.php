<?php
// Drive edit bus of a site repo, on the host (was drive-edit-drain.yml on Actions, woken by Apps Script pollBridge).
// A chat or Cowork that can only create Drive files drops "editreq-*.md" into the site's bridge folder:
//   ---
//   action: edit | read | delete
//   target: path/in/repo
//   reqid: <unique>          (optional; default = file name)
//   ---
//   <full new content, for edit>
// Every tick this reads them, applies edits/deletes to the repo's main through the contents API (one commit each),
// answers reads in bus-read.md, logs every result at the top of bus-status.md, and trashes the request.
// Same guards as the Python drain: relative path only, no "..", never .github/ .githooks/ Sys.scripts/ Sys.registry/,
// body ≤ 1 MB. A deploy request written this way carries no approval stamp, so the deploy queue will not run it.
require_once __DIR__ . '/lib.php';

const EB_PREFIX = 'editreq-';
const EB_BLOCKED = ['.github/', '.githooks/', 'Sys.scripts/', 'Sys.registry/'];
const EB_MAX_EDIT = 1000000;
const EB_MAX_STATUS_LINES = 60;

function eb_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

// Service-account token. The key file sits outside public_html; its path comes from the site's
// 'drive_sa_file' (relative to this host's root, or absolute — e.g. the ops host's drive-sa.json).
function eb_token(array $site): string {
    static $cache = [];
    $f = (string) ($site['editbus']['sa_file'] ?? '');
    if ($f === '') return '';
    if (isset($cache[$f])) return $cache[$f];
    $path = $f[0] === '/' ? $f : DH_ROOT . '/' . $f;
    $sa = @json_decode((string) @file_get_contents($path), true);
    if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email'])) return $cache[$f] = '';
    $now = time();
    $unsigned = eb_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . eb_b64url(json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/drive',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign($unsigned, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) return $cache[$f] = '';
    [$c, $raw] = dh_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . eb_b64url($sig)]));
    $j = json_decode((string) $raw, true);
    return $cache[$f] = ($c === 200 && !empty($j['access_token'])) ? $j['access_token'] : '';
}

function eb_drive(string $tok, string $method, string $url, ?string $body = null, string $ctype = 'application/json'): array {
    [$c, $raw] = dh_http($method, $url, array_merge(["Authorization: Bearer $tok"], $body !== null ? ["Content-Type: $ctype"] : []), $body);
    return [$c, (string) $raw];
}

function eb_list(string $tok, string $folder, string $q): array {
    [$c, $raw] = eb_drive($tok, 'GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => "$q and '$folder' in parents and trashed = false", 'fields' => 'files(id,name,createdTime)', 'orderBy' => 'createdTime',
        'pageSize' => 200, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']));
    if ($c !== 200) throw new RuntimeException("drive list HTTP $c");
    return json_decode($raw, true)['files'] ?? [];
}

function eb_media(string $tok, string $id): ?string {
    [$c, $raw] = eb_drive($tok, 'GET', "https://www.googleapis.com/drive/v3/files/$id?alt=media&supportsAllDrives=true");
    return $c === 200 ? $raw : null;
}

// Overwrite a fixed file that a connector created once (a service account cannot create files in a user's folder).
function eb_overwrite(string $tok, string $folder, string $name, string $text): bool {
    $f = eb_list($tok, $folder, "name = '$name'");
    if (!$f) return false;
    [$c] = eb_drive($tok, 'PATCH', 'https://www.googleapis.com/upload/drive/v3/files/' . $f[0]['id'] . '?uploadType=media&supportsAllDrives=true', $text, 'text/markdown');
    return $c === 200;
}

function eb_parse(string $raw): array {
    $meta = []; $body = $raw;
    if (strncmp($raw, '---', 3) === 0 && ($end = strpos($raw, "\n---", 3)) !== false) {
        foreach (preg_split('/\r?\n/', trim(substr($raw, 3, $end - 3), "\n")) as $ln)
            if (strpos($ln, ':') !== false) { [$k, $v] = explode(':', $ln, 2); $meta[strtolower(trim($k))] = trim($v); }
        $body = substr($raw, $end + 4);
        if (strncmp($body, "\n", 1) === 0) $body = substr($body, 1);
    }
    return [$meta, $body];
}

function eb_safe(string $t): ?string {
    $t = ltrim(trim($t), '/');
    if ($t === '' || strpos($t, ':') !== false || in_array('..', explode('/', $t), true)) return null;
    foreach (EB_BLOCKED as $b) if (strncmp($t, $b, strlen($b)) === 0) return null;
    return $t;
}

function eb_apply(array $site, array $meta, string $body, string $reqid, array &$reads): array {
    $action = strtolower($meta['action'] ?? '');
    $t = eb_safe((string) ($meta['target'] ?? ''));
    $rec = ['reqid' => $reqid, 'action' => $action, 'target' => $meta['target'] ?? '', 'mark' => '❌', 'note' => ''];
    if ($t === null) { $rec['note'] = 'مسیرِ target نامعتبر/ناامن یا زیرساختیِ ممنوع'; return $rec; }
    $path = '/contents/' . dh_enc_path($t);
    [$gc, $cur] = dh_gh($site['repo'], 'GET', $path . '?ref=main');
    if ($action === 'edit') {
        if (strlen($body) > EB_MAX_EDIT) { $rec['note'] = 'بدنه بزرگ‌تر از حدِ مجاز'; return $rec; }
        $put = ['message' => "edit-bus: $t (reqid $reqid, sites-host)\n\nWriter: drive-edit-drain · sites-host", 'content' => base64_encode($body), 'branch' => 'main'];
        if ($gc === 200 && !empty($cur['sha'])) $put['sha'] = $cur['sha'];
        [$wc, $w] = dh_gh($site['repo'], 'PUT', $path, $put);
        if ($wc === 200 || $wc === 201) { $rec['mark'] = '✅'; $rec['note'] = 'کامیت شد (sha ' . substr($w['commit']['sha'] ?? '', 0, 7) . ')'; }
        else $rec['note'] = "کامیت نشد (HTTP $wc)";
    } elseif ($action === 'delete') {
        if ($gc !== 200 || ($cur['type'] ?? '') !== 'file') { $rec['note'] = $gc === 200 ? 'target پوشه است، نه فایل' : 'فایل نیست'; return $rec; }
        [$dc, $d] = dh_gh($site['repo'], 'DELETE', $path, ['message' => "edit-bus: delete $t (reqid $reqid, sites-host)\n\nWriter: drive-edit-drain · sites-host", 'sha' => $cur['sha'], 'branch' => 'main']);
        if ($dc === 200) { $rec['mark'] = '✅'; $rec['note'] = 'حذف شد (sha ' . substr($d['commit']['sha'] ?? '', 0, 7) . ')'; }
        else $rec['note'] = "حذف نشد (HTTP $dc)";
    } elseif ($action === 'read') {
        $rec['mark'] = '📄';
        if ($gc === 200 && ($cur['type'] ?? '') === 'file') { $reads[] = [$meta['target'], $reqid, base64_decode((string) $cur['content'])]; $rec['note'] = 'محتوا در bus-read.md گذاشته شد'; }
        else { $reads[] = [$meta['target'], $reqid, '(فایل وجود ندارد)']; $rec['note'] = 'فایلِ هدف وجود ندارد'; }
    } else {
        $rec['note'] = "action ناشناخته: '$action'";
    }
    return $rec;
}

function eb_drain(string $name, array $site): array {
    $folder = trim((string) (dh_secrets()[$site['editbus']['folder_secret'] ?? ''] ?? ''));
    if ($folder === '') return ['skipped' => 'folder id not in secrets.php'];
    if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $folder)) throw new RuntimeException('bad folder id');
    $tok = eb_token($site);
    if ($tok === '') throw new RuntimeException('drive service account missing or refused');
    $reqs = array_values(array_filter(eb_list($tok, $folder, "name contains '" . EB_PREFIX . "'"), fn($f) => strncmp($f['name'], EB_PREFIX, strlen(EB_PREFIX)) === 0));
    if (!$reqs) return ['requests' => 0];
    $st = dh_state(); $done = $st['editbus_done'][$name] ?? [];
    $results = []; $reads = [];
    foreach ($reqs as $f) {
        $reqid = preg_replace('/\.md$/', '', $f['name']);
        $raw = eb_media($tok, $f['id']);
        if ($raw === null) { $results[] = ['reqid' => $reqid, 'action' => '?', 'target' => '', 'mark' => '❌', 'note' => 'دانلود نشد']; continue; }
        [$meta, $body] = eb_parse($raw);
        $reqid = $meta['reqid'] ?? $reqid;
        if (!isset($done[$reqid])) {
            $results[] = eb_apply($site, $meta, $body, $reqid, $reads);
            $done[$reqid] = time();
        }
        // trash needs only writer rights (delete would need ownership); dedup by reqid keeps it safe if this fails
        eb_drive($tok, 'PATCH', 'https://www.googleapis.com/drive/v3/files/' . $f['id'] . '?supportsAllDrives=true', json_encode(['trashed' => true]));
    }
    $st['editbus_done'][$name] = array_slice($done, -500, null, true);
    dh_state_set('editbus_done', $st['editbus_done']);
    if ($reads) {
        $blocks = ['# bus-read — محتوای فعلیِ فایل‌های درخواست‌شده (خودکار؛ دستی ویرایش نکن)', ''];
        foreach ($reads as [$t, $rid, $c]) array_push($blocks, "## $t  (reqid: $rid)", '', '```', $c, '```', '');
        eb_overwrite($tok, $folder, 'bus-read.md', implode("\n", $blocks) . "\n");
    }
    if ($results) {
        $lines = ['### اجرا sites-host ' . gmdate('Y-m-d H:i') . ' UTC'];
        foreach ($results as $r) $lines[] = "- {$r['mark']} `{$r['target']}` [{$r['action']}] reqid=`{$r['reqid']}` — {$r['note']}";
        $lines[] = '';
        $files = eb_list($tok, $folder, "name = 'bus-status.md'");
        $prev = $files ? (string) eb_media($tok, $files[0]['id']) : '';
        if (strncmp($prev, '# bus-status', 12) === 0) $prev = (string) substr($prev, strpos($prev, "\n") ?: strlen($prev));
        $old = array_slice(preg_split('/\r?\n/', trim($prev, "\n")), 0, EB_MAX_STATUS_LINES);
        eb_overwrite($tok, $folder, 'bus-status.md', "# bus-status — نتیجهٔ درخواست‌های ویرایش (خودکار؛ تازه‌ترین بالا؛ دستی ویرایش نکن)\n\n" . implode("\n", array_merge($lines, $old)) . "\n");
    }
    dh_log("$name editbus: " . count($reqs) . ' request(s), ' . count($results) . ' applied');
    return ['requests' => count($reqs), 'applied' => count($results)];
}

function dh_run_editbus(): void {
    $out = [];
    foreach (dh_sites() as $name => $site) {
        if (empty($site['editbus'])) continue;
        try { $out[$name] = ['ok' => true, 'utc' => gmdate('c')] + eb_drain($name, $site); }
        catch (Throwable $e) { $out[$name] = ['ok' => false, 'utc' => gmdate('c'), 'error' => substr($e->getMessage(), 0, 200)]; }
    }
    if ($out) dh_state_set('editbus', $out);
}
