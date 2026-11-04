<?php
// pool-watch on the host (replaces Sys.scripts/apps-script/pool-watch.gs, which ran inside the old Google account).
// Every minute: a new file in the BACKUP account's «Video Under Review» folder dispatches the pool chain once,
// with the same rules as the Apps Script it replaces: first run adopts what is already there, 5-minute
// cooldown, at most 12 dispatches a Tehran day, a bounded list of seen ids. Drive is read with the holding's
// single account (studio host var); the dispatch uses this host's GH_DISPATCH_TOKEN. Owner: skill-trainer.
const POOL_FOLDER_ID = '1M4WgkkgXkg6m3PqFiw_oEMO7dXtdgnY0';   // My-Drive/Video Under Review (BACKUP)
const POOL_WORKFLOW = 'intake-pool.yml';
const POOL_REF = 'main';
const POOL_COOLDOWN_S = 300;
const POOL_MAX_PER_DAY = 12;
const POOL_SEEN_CAP = 400;

// ---- the holding's only Drive account (since 1405-08-13): the studio host's default account (BACKUP).
// Its client and refresh token live only in studio.hohedoc.ir/var (0600, same server account); no host keeps
// its own Google key any more. Returns [access_token, expires_at, root_id] or throws.
function hohe_backup_drive(string $siteRoot, bool $force = false): array {
    $sv = $siteRoot . '/../studio.hohedoc.ir/var';
    $d = @json_decode((string) @file_get_contents("$sv/drive-default.json"), true);
    if (!is_array($d) || empty($d['account']) || empty($d['root'])) throw new RuntimeException('studio host has no default Drive account');
    $cache = "$sv/drive-token-shared.json";
    $c = @json_decode((string) @file_get_contents($cache), true);
    if (!$force && is_array($c) && ($c['exp'] ?? 0) > time() + 120) return [$c['tok'], $c['exp'], $d['root']];
    $cl = @json_decode((string) @file_get_contents("$sv/drive-web-client.json"), true);
    $ac = @json_decode((string) @file_get_contents("$sv/drive-accounts/" . preg_replace('/[^A-Z0-9_]/', '', $d['account']) . '.json'), true);
    if (empty($cl['id']) || empty($cl['secret']) || empty($ac['refresh_token'])) throw new RuntimeException('BACKUP credentials missing on studio host');
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $cl['id'], 'client_secret' => $cl['secret'], 'refresh_token' => $ac['refresh_token'], 'grant_type' => 'refresh_token'])]);
    $r = json_decode((string) curl_exec($ch), true); curl_close($ch);
    if (empty($r['access_token'])) throw new RuntimeException('google refused the BACKUP refresh');
    $exp = time() + (int) ($r['expires_in'] ?? 3000);
    file_put_contents("$cache.tmp", json_encode(['tok' => $r['access_token'], 'exp' => $exp])); @chmod("$cache.tmp", 0600); rename("$cache.tmp", $cache);
    return [$r['access_token'], $exp, $d['root']];
}

function pool_watch_tick(): void {
    $sp = trainer_state_path('pool-watch.json');
    $st = @json_decode((string) @file_get_contents($sp), true) ?: [];
    [$tok] = hohe_backup_drive(TRAINER_ROOT);
    $q = http_build_query(['q' => "'" . POOL_FOLDER_ID . "' in parents and trashed=false and mimeType != 'application/vnd.google-apps.folder'",
        'pageSize' => 200, 'fields' => 'files(id,name)']);
    $ch = curl_init('https://www.googleapis.com/drive/v3/files?' . $q);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok]]);
    $r = json_decode((string) curl_exec($ch), true); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !is_array($r)) throw new RuntimeException("drive list HTTP $code");
    $seen = $st['seen'] ?? null;
    $ids = array_column($r['files'] ?? [], 'id');
    if ($seen === null) {                                     // first run: adopt, never dispatch for history
        $st['seen'] = array_slice($ids, -POOL_SEEN_CAP); $st['adopted'] = count($ids);
        file_put_contents($sp, json_encode($st), LOCK_EX);
        trainer_log('pool-watch', 'first run, adopted ' . count($ids) . ' files');
        return;
    }
    $fresh = array_values(array_diff($ids, $seen));
    if (!$fresh) return;
    if (time() - (int) ($st['last'] ?? 0) < POOL_COOLDOWN_S) return;   // not marked seen: picked up after cooldown
    $day = gmdate('Y-m-d', time() + 12600);
    if (($st['day'] ?? '') !== $day) { $st['day'] = $day; $st['count'] = 0; }
    if (($st['count'] ?? 0) >= POOL_MAX_PER_DAY) return;
    (new GitHub())->dispatch(POOL_WORKFLOW, POOL_REF, ['download' => 'yes', 'limit' => '0']);
    $st['last'] = time(); $st['count'] = ($st['count'] ?? 0) + 1;
    $st['seen'] = array_slice(array_values(array_unique(array_merge($seen, $fresh))), -POOL_SEEN_CAP);
    file_put_contents($sp, json_encode($st), LOCK_EX);
    trainer_log('pool-watch', 'dispatched for ' . count($fresh) . ' new file(s)');
}
