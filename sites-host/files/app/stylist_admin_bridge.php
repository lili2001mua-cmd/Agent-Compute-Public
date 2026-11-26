<?php
// Stylist Admin Bridge for My-Control-Project (control.hohedoc.ir/api.php?op=stylist-admin-status|stylist-admin-action).
// Publishes a unified, portable (zero-hardcoding) operational snapshot to control.hohedoc.ir/var/stylist-admin-state.json
// and executes signed owner actions queued by the Control App in control.hohedoc.ir/var/stylist-admin-req/*.json.

const STYLIST_ADMIN_RELEASE_CACHE_SEC = 300; // refresh dual-track releases from pwa every 5 minutes (or on action)
const STYLIST_ADMIN_ALLOWED_ACTIONS = [
    'release_status'     => 1,
    'release_assign_900' => 1,
    'release_promote'    => 1,
    'release_rollback'   => 1,
    'db_parity_now'      => 1,
];

function stylist_admin_atomic_json(string $path, array $data): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $tmp = $path . '.tmp.' . getmypid();
    $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($raw === false) return false;
    if (@file_put_contents($tmp, $raw . "\n", LOCK_EX) === false) return false;
    @chmod($tmp, 0644);
    return @rename($tmp, $path);
}

function stylist_admin_read_json(string $path, $fallback = null) {
    if (!is_file($path)) return $fallback;
    $j = @json_decode((string) @file_get_contents($path), true);
    return is_array($j) ? $j : $fallback;
}

/** Return stylistcrm site config from dh_sites() (or dh_site() in isolated tests). */
function stylist_admin_site(): ?array {
    if (function_exists('dh_sites')) {
        $all = dh_sites();
        if (isset($all['stylistcrm']) && is_array($all['stylistcrm'])) return $all['stylistcrm'];
    }
    if (function_exists('dh_site')) {
        $s = dh_site('stylistcrm');
        if (is_array($s)) return $s;
    }
    return null;
}

/** Extract portable host URLs dynamically from sites.php (zero hardcoding for STY-18 migration). */
function stylist_admin_hosts_info(): array {
    $out = ['primary_pwa' => '', 'primary_hub' => '', 'standby_pwa' => '', 'standby_hub' => ''];
    $site = stylist_admin_site();
    if (!is_array($site)) return $out;
    try {
        $envs = $site['envs'] ?? [];
        foreach (['pwa' => 'primary_pwa', 'hub' => 'primary_hub', 'pwa-de' => 'standby_pwa', 'hub-de' => 'standby_hub'] as $k => $field) {
            $raw = $envs[$k] ?? '';
            $u = is_array($raw) ? (string) ($raw['url'] ?? '') : (string) $raw;
            if ($u !== '') {
                $p = parse_url($u);
                if (!empty($p['scheme']) && !empty($p['host'])) {
                    $out[$field] = $p['scheme'] . '://' . $p['host'];
                }
            }
        }
    } catch (Throwable $e) {
        // leave empty if sites.php unavailable in isolated unit test
    }
    return $out;
}

/** Fetch dual-track release status from pwa (cached 5 min unless forced). */
function stylist_admin_fetch_releases(bool $force = false): array {
    $cachePath = function_exists('dh_var') ? dh_var('stylist-releases-cache.json') : sys_get_temp_dir() . '/stylist-releases-cache.json';
    $cached = stylist_admin_read_json($cachePath, null);
    if (!$force && is_array($cached) && !empty($cached['ok']) && (time() - (int) ($cached['fetched_at'] ?? 0)) < STYLIST_ADMIN_RELEASE_CACHE_SEC) {
        return $cached;
    }

    $res = [
        'ok'             => false,
        'fetched_at'     => time(),
        'fetched_at_utc' => gmdate('c'),
        'default_stable' => null,
        'branch_900'     => null,
        'releases'       => [],
        'assignments'    => [],
        'error'          => null,
    ];
    $site = stylist_admin_site();
    if (!is_array($site) || !function_exists('dh_post')) {
        $res['error'] = 'bridge_unavailable';
        return $res;
    }

    try {
        [$code, $j, $err] = dh_post($site, 'pwa', ['action' => 'release', 'op' => 'status', 'files' => []]);
        if ($code === 200 && !empty($j['ok'])) {
            $st = is_array($j['state'] ?? null) ? $j['state'] : [];
            $res['ok']             = true;
            $res['default_stable'] = $st['default_stable'] ?? null;
            $res['branch_900']     = $st['branch_overrides']['900'] ?? ($st['default_stable'] ?? null);
            $res['releases']       = is_array($j['releases'] ?? null) ? array_slice($j['releases'], 0, 12) : [];
            $res['assignments']    = is_array($j['assignments'] ?? null) ? array_slice($j['assignments'], 0, 20) : [];
        } else {
            $res['error'] = $err ?: ('http_' . $code . '_' . (string) ($j['error'] ?? 'fail'));
        }
    } catch (Throwable $e) {
        $res['error'] = substr($e->getMessage(), 0, 160);
    }

    stylist_admin_atomic_json($cachePath, $res);
    return $res;
}

/** Build and publish unified Stylist Admin snapshot to control.hohedoc.ir/var/stylist-admin-state.json. */
function stylist_admin_publish_snapshot(bool $forceReleases = false, ?string $controlVarOverride = null): array {
    $hosts = stylist_admin_hosts_info();

    $syncState = function_exists('dh_var')
        ? (stylist_admin_read_json(dh_var('de-mirror-state.json'), null) ?? stylist_admin_read_json(dh_var('de-mirror-sync-state.json'), null))
        : null;
    $sla24h    = function_exists('dh_var') ? stylist_admin_read_json(dh_var('de-mirror-sla-24h.json'), null) : null;
    $parity    = function_exists('db_parity_state_path') ? stylist_admin_read_json(db_parity_state_path(), null) : null;
    $parity7   = function_exists('db_parity_7day_path') ? stylist_admin_read_json(db_parity_7day_path(), null) : null;
    $releases  = stylist_admin_fetch_releases($forceReleases);

    $snapshot = [
        'schema'         => 'stylist-admin-v1',
        'updated_at'     => time(),
        'updated_at_utc' => gmdate('c'),
        'hosts'          => $hosts,
        'replication'    => [
            'ok'            => (bool) ($syncState['ok'] ?? false),
            'last_seq'      => (int) ($syncState['last_seq'] ?? 0),
            'total_applied' => (int) ($syncState['total_applied'] ?? 0),
            'lag_seconds'   => $syncState['lag_seconds'] ?? null,
            'seq_gap'       => $syncState['seq_gap'] ?? null,
            'last_run_utc'  => $syncState['last_run_utc'] ?? null,
            'sla_24h'       => is_array($sla24h) ? [
                'ticks_24h'    => (int) ($sla24h['ticks_24h'] ?? 0),
                'breaches_24h' => (int) ($sla24h['breaches_24h'] ?? 0),
                'max_lag_24h'  => $sla24h['max_lag_24h'] ?? null,
                'sla_pass_pct' => $sla24h['sla_pass_pct'] ?? null,
                'alert_active' => (bool) ($sla24h['alert_active'] ?? false),
            ] : null,
        ],
        'db_parity'      => is_array($parity) ? [
            'ok'                  => (bool) ($parity['ok'] ?? false),
            'all_match'           => (bool) ($parity['all_match'] ?? false),
            'tables_primary'      => (int) ($parity['tables_primary'] ?? 0),
            'tables_standby'      => (int) ($parity['tables_standby'] ?? 0),
            'mismatched_tables'   => $parity['mismatched_tables'] ?? [],
            'alert_active'        => (bool) ($parity['alert_active'] ?? false),
            'last_run_utc'        => $parity['last_run_utc'] ?? null,
            'consecutive_days'    => (int) ($parity7['consecutive_clean_days'] ?? 0),
            'ready_7day_gate'     => (bool) ($parity7['ready_7day_gate'] ?? false),
        ] : null,
        'releases'       => $releases,
        'apk_download'   => [
            'version'     => '2.4.0',
            'build'       => '0.2.0+2',
            'page_url'    => ($hosts['primary_pwa'] ?: 'https://pwa.redlilium.ir') . '/app',
            'standby_url' => ($hosts['standby_pwa'] ?: 'https://pwa-de.hohedoc.ir') . '/app',
        ],
    ];

    $ctrlDir = $controlVarOverride ?? (function_exists('pb_control_var') ? pb_control_var() : '');
    $published = false;
    if ($ctrlDir !== '' && is_dir($ctrlDir)) {
        $published = stylist_admin_atomic_json($ctrlDir . '/stylist-admin-state.json', $snapshot);
        if (function_exists('dh_secrets')) {
            $depSec = trim((string) (dh_secrets()['STYLISTCRM_DEPLOY_SECRET'] ?? ''));
            if ($depSec !== '') {
                $gateKey = hash_hmac('sha256', 'hub-gate-sso-v1', $depSec);
                $keyPath = $ctrlDir . '/hub-gate.key';
                $cur = is_file($keyPath) ? trim((string) @file_get_contents($keyPath)) : '';
                if ($cur !== $gateKey) {
                    $tmp = $keyPath . '.tmp.' . getmypid();
                    if (@file_put_contents($tmp, $gateKey . "\n", LOCK_EX) !== false) {
                        @chmod($tmp, 0600);
                        @rename($tmp, $keyPath);
                    }
                }
            }
        }
    }
    $snapshot['published_to_control'] = $published;
    return $snapshot;
}

/** Validate and execute one queued Stylist Admin action from control.hohedoc.ir/var/stylist-admin-req/. */
function stylist_admin_execute_action(array $req, ?callable $bridgeFn = null, ?callable $parityFn = null): array {
    $id     = (string) ($req['id'] ?? 'unknown');
    $action = (string) ($req['action'] ?? '');
    $rid    = strtoupper(trim((string) ($req['release_id'] ?? '')));
    $reason = trim((string) ($req['reason'] ?? 'control-app'));

    if (!isset(STYLIST_ADMIN_ALLOWED_ACTIONS[$action])) {
        return ['ok' => false, 'id' => $id, 'action' => $action, 'at' => time(), 'at_utc' => gmdate('c'), 'error' => 'action_not_allowed'];
    }

    if (in_array($action, ['release_assign_900', 'release_promote'], true) && !preg_match('/^R\d{4}$/', $rid)) {
        return ['ok' => false, 'id' => $id, 'action' => $action, 'at' => time(), 'at_utc' => gmdate('c'), 'error' => 'invalid_release_id'];
    }
    if ($action === 'release_rollback' && $rid !== '' && !preg_match('/^R\d{4}$/', $rid)) {
        return ['ok' => false, 'id' => $id, 'action' => $action, 'at' => time(), 'at_utc' => gmdate('c'), 'error' => 'invalid_release_id'];
    }

    if ($action === 'db_parity_now') {
        $r = $parityFn ? $parityFn() : (function_exists('db_parity_audit_run') ? db_parity_audit_run() : ['ok' => false, 'error' => 'no_parity_runner']);
        return [
            'ok'     => !empty($r['ok']),
            'id'     => $id,
            'action' => $action,
            'at'     => time(),
            'at_utc' => gmdate('c'),
            'result' => [
                'all_match'      => (bool) ($r['all_match'] ?? false),
                'tables_primary' => (int) ($r['tables_primary'] ?? 0),
                'tables_standby' => (int) ($r['tables_standby'] ?? 0),
            ],
            'error'  => $r['error'] ?? null,
        ];
    }

    if ($action === 'release_status') {
        $rel = $bridgeFn
            ? $bridgeFn(['action' => 'release', 'op' => 'status', 'files' => []])
            : stylist_admin_fetch_releases(true);
        return ['ok' => !empty($rel['ok']), 'id' => $id, 'action' => $action, 'at' => time(), 'at_utc' => gmdate('c'), 'result' => $rel, 'error' => $rel['error'] ?? null];
    }

    $payload = ['action' => 'release', 'files' => [], 'reason' => mb_substr($reason, 0, 120)];
    if ($action === 'release_assign_900') {
        $payload['op']         = 'assign';
        $payload['company_id'] = 900;
        $payload['release_id'] = $rid;
    } elseif ($action === 'release_promote') {
        $payload['op']         = 'promote';
        $payload['release_id'] = $rid;
    } elseif ($action === 'release_rollback') {
        $payload['op'] = 'rollback';
        if ($rid !== '') $payload['target_release_id'] = $rid;
    }

    if ($bridgeFn) {
        $j = $bridgeFn($payload);
        $ok = !empty($j['ok']);
        $err = $ok ? null : (string) ($j['error'] ?? 'bridge_fail');
    } else {
        $site = stylist_admin_site();
        if (!is_array($site) || !function_exists('dh_post')) {
            $ok = false; $j = null; $err = 'bridge_unavailable';
        } else {
            [$code, $j, $err] = dh_post($site, 'pwa', $payload);
            $ok = ($code === 200 && !empty($j['ok']));
            if (!$ok && !$err) $err = 'http_' . $code . '_' . (string) ($j['error'] ?? 'fail');
        }
    }

    return [
        'ok'         => $ok,
        'id'         => $id,
        'action'     => $action,
        'release_id' => $rid !== '' ? $rid : null,
        'at'         => time(),
        'at_utc'     => gmdate('c'),
        'result'     => is_array($j) ? $j : null,
        'error'      => $err,
    ];
}

/** Drain queued Control App actions from control.hohedoc.ir/var/stylist-admin-req/. */
function stylist_admin_process_queue(?string $controlVarOverride = null, ?callable $bridgeFn = null, ?callable $parityFn = null): array {
    $ctrlDir = $controlVarOverride ?? (function_exists('pb_control_var') ? pb_control_var() : '');
    if ($ctrlDir === '' || !is_dir($ctrlDir)) return ['processed' => 0];
    $reqDir = $ctrlDir . '/stylist-admin-req';
    if (!is_dir($reqDir)) return ['processed' => 0];

    $files = glob($reqDir . '/*.json') ?: [];
    sort($files);
    $processed = 0;
    $last = null;

    foreach ($files as $f) {
        $req = stylist_admin_read_json($f, null);
        @unlink($f);
        if (!is_array($req)) continue;
        $last = stylist_admin_execute_action($req, $bridgeFn, $parityFn);
        stylist_admin_atomic_json($ctrlDir . '/stylist-admin-last-action.json', $last);
        if (function_exists('dh_log')) {
            dh_log('stylist-admin ' . ($last['action'] ?? '') . ' id=' . ($last['id'] ?? '') . ' ok=' . (!empty($last['ok']) ? '1' : '0'));
        }
        $processed++;
    }
    return ['processed' => $processed, 'last' => $last];
}

function stylist_admin_tick(): array {
    $q = stylist_admin_process_queue();
    $snap = stylist_admin_publish_snapshot($q['processed'] > 0);
    return ['processed' => $q['processed'], 'published' => $snap['published_to_control']];
}
