<?php
// Hourly safety net for the nightly chain's temporary Drive links (1405-08-20).
// The chain opens an "anyone with the link" reader permission on a bundle's media under My-Drive/intake-pool/ so the
// public extraction runner can download it, and revokes it when it lands. When the session that should revoke cannot
// reach this host (its network refuses *.hohedoc.ir), the link stayed open: on 2026-11-11, 22 links were found, 8 of
// them open for 8 days. This sweep runs here, on the host that owns the Drive account, so it needs no session, no
// GitHub runner and no setting anywhere: any public file under intake-pool/ that has been public for more than 24 hours
// loses its "anyone" permission. Files outside intake-pool/ are never touched.
// Revoking after 24h never breaks a later land: the public runner downloads the media within minutes of `start`, and
// `land` only fetches the runner's artifact from GitHub, not from Drive; a new `start` opens fresh links.
// Flaw row (Agent-Project-Backup known-flaws-log): drive-relay-is-a-single-road-and-its-failures-kill-the-night.

const LINK_SWEEP_EVERY_S = 3600;
const LINK_SWEEP_MAX_AGE_S = 86400;
const LINK_SWEEP_POOL_PARENT = '1E0X_anS6bZtlPzL6hsWs2pN6cQrnHqkW';   // My-Drive (Sys.scripts/pool_media_backend.py)
const LINK_SWEEP_POOL_NAME = 'intake-pool';
const LINK_SWEEP_MAX_FILES = 500;

function link_sweep_due(): bool {
    $last = (int) @file_get_contents(studio_var('link-sweep-last.txt'));
    return time() - $last >= LINK_SWEEP_EVERY_S;
}

/** id of My-Drive/intake-pool, or '' when it does not exist (nothing to sweep). */
function link_sweep_pool_id(): string {
    $q = sprintf("name='%s' and '%s' in parents and mimeType='%s' and trashed=false",
        LINK_SWEEP_POOL_NAME, LINK_SWEEP_POOL_PARENT, FOLDER_MIME);
    $r = drive_call('GET', '/files', ['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1,
        'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']);
    return (string) ($r['files'][0]['id'] ?? '');
}

/** True when $id sits (at any depth up to 4) under the pool folder. Parents are cached per run. */
function link_sweep_under_pool(string $id, string $pool, array &$parents): bool {
    for ($depth = 0; $depth < 4; $depth++) {
        if (!array_key_exists($id, $parents)) {
            $r = drive_call('GET', '/files/' . rawurlencode($id), ['fields' => 'parents', 'supportsAllDrives' => 'true']);
            $parents[$id] = (string) ($r['parents'][0] ?? '');
        }
        $id = $parents[$id];
        if ($id === '') return false;
        if ($id === $pool) return true;
    }
    return false;
}

/** One pass. Returns a small report; never throws (the tick must go on). */
function link_sweep_step(bool $force = false): array {
    if (!$force && !link_sweep_due()) return ['skipped' => 'not due'];
    file_put_contents(studio_var('link-sweep-last.txt'), (string) time());
    $rep = ['public' => 0, 'tracked' => 0, 'revoked' => [], 'errors' => 0];
    try {
        if (!drive_default()) return $rep + ['skipped' => 'no default Drive account'];
        $pool = link_sweep_pool_id();
        if ($pool === '') return $rep + ['skipped' => 'no intake-pool folder'];
        $seenFile = studio_var('link-sweep-seen.json');
        $seen = json_decode((string) @file_get_contents($seenFile), true) ?: [];
        $now = time();
        $live = [];
        $parents = [];
        $token = '';
        do {
            $q = ['q' => "visibility='anyoneWithLink' and trashed=false and mimeType!='" . FOLDER_MIME . "'",
                'fields' => 'nextPageToken,files(id,parents)', 'pageSize' => 100,
                'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true'];
            if ($token !== '') $q['pageToken'] = $token;
            $r = drive_call('GET', '/files', $q);
            foreach ($r['files'] ?? [] as $f) {
                $rep['public']++;
                $id = (string) $f['id'];
                $parents[$id] = (string) ($f['parents'][0] ?? '');
                if (!link_sweep_under_pool($id, $pool, $parents)) continue;
                $rep['tracked']++;
                $live[$id] = $seen[$id] ?? $now;
                if ($now - $live[$id] < LINK_SWEEP_MAX_AGE_S) continue;
                try {
                    $perms = drive_call('GET', '/files/' . rawurlencode($id) . '/permissions',
                        ['fields' => 'permissions(id,type)', 'supportsAllDrives' => 'true']);
                    foreach ($perms['permissions'] ?? [] as $p) {
                        if (($p['type'] ?? '') !== 'anyone') continue;
                        drive_call('DELETE', '/files/' . rawurlencode($id) . '/permissions/' . rawurlencode((string) $p['id']),
                            ['supportsAllDrives' => 'true']);
                    }
                    $rep['revoked'][] = $id;
                    unset($live[$id]);
                } catch (Throwable $e) {
                    $rep['errors']++;
                    studio_log('link-sweep', 'revoke failed for one file: ' . substr($e->getMessage(), 0, 200));
                }
            }
            $token = (string) ($r['nextPageToken'] ?? '');
        } while ($token !== '' && $rep['public'] < LINK_SWEEP_MAX_FILES);
        if ($token !== '') { $rep['cap_hit'] = true; studio_log('link-sweep', 'cap hit: more than ' . LINK_SWEEP_MAX_FILES . ' public files; the rest waits for the next pass'); }
        file_put_contents($seenFile . '.tmp', json_encode($live));
        @chmod($seenFile . '.tmp', 0600);
        rename($seenFile . '.tmp', $seenFile);
    } catch (Throwable $e) {
        $rep['errors']++;
        studio_log('link-sweep', 'pass failed: ' . substr($e->getMessage(), 0, 200));
    }
    $rep['revoked_count'] = count($rep['revoked']);
    file_put_contents(studio_var('link-sweep-report.json'), json_encode($rep + ['at' => gmdate('c')]));
    if ($rep['revoked_count']) studio_log('link-sweep', 'revoked ' . $rep['revoked_count'] . ' public link(s) older than 24h');
    return $rep;
}
