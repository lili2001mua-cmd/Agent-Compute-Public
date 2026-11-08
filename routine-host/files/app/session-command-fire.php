<?php
// «فرمانِ مستقیم به سشن» of the My-Control-Project app (ارکستر github-backed-session-ledger).
// control.hohedoc.ir writes the command into the bundle's live-session inbox on GitHub (op=session-command),
// then drops a request file in its own var/sc-req/ (signed by the phone). This cron (same hosting user, so it
// can read the sibling folder) fires the account's dispatcher with job=session-command run=sc-<task>-<yyyymmddhhmm>;
// the dispatcher runs Memory-Routine-Runbook/session-command.md, which reads the inbox, moves one step, and checkpoints.
// One request per tick; the same safety as the clock: armed + supported country + healthy key; 429 never rotates.
require_once __DIR__ . '/dispatch.php';

if (!function_exists('f_control_var')) {
    function f_control_var(): string { return dirname((string) realpath(R_ROOT)) . '/control.hohedoc.ir/var'; }
}

function sc_state(array $s): void {
    $f = f_control_var() . '/sc-fire-state.json';
    $all = @json_decode((string) @file_get_contents($f), true) ?: [];
    $all[$s['task']] = $s;
    @file_put_contents($f . '.tmp', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($f . '.tmp', 0600);
    @rename($f . '.tmp', $f);
}

function r_session_command_fire(): void {
    $reqs = glob(f_control_var() . '/sc-req/*.json') ?: [];
    if (!$reqs) return;
    sort($reqs);
    $file = $reqs[0];
    $q = json_decode((string) @file_get_contents($file), true) ?: [];
    @unlink($file);                                         // one try per request; the app shows progress from the ledger
    $task = (string) ($q['task'] ?? '');
    if (!preg_match('/^(b[0-9]{1,8}|t-[a-z0-9-]{1,40})$/', $task)) return;
    $d = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $runId = 'sc-' . $task . '-' . $d->format('YmdHi');
    $base = ['task' => $task, 'run' => $runId, 'at' => time()];
    // a session fired for this task less than 5 minutes ago is probably still draining the inbox; let it.
    $fs = @json_decode((string) @file_get_contents(f_control_var() . '/sc-fire-state.json'), true) ?: [];
    if (($fs[$task]['state'] ?? '') === 'fired' && time() - (int) ($fs[$task]['at'] ?? 0) < 300) { sc_state($base + ['state' => 'coalesced']); return; }
    if (!r_armed() || !r_country_ok()) { sc_state($base + ['state' => 'failed', 'error' => !r_armed() ? 'firing is not armed' : 'host country not confirmed']); return; }
    $jobs = r_jobs()['jobs'] ?? [];
    $job = null; foreach ($jobs as $j) if (($j['id'] ?? '') === 'session-command') $job = $j;
    if (!$job) { sc_state($base + ['state' => 'failed', 'error' => 'session-command missing from jobs.json']); return; }
    $keys = r_keys()['keys'];
    $health = r_read_json('keys-health.json');
    $scope = $job['account_class'];
    foreach ($job['accounts'] as $alias) {
        $k = $keys[$alias][$scope] ?? null;
        $hk = "$alias-$scope";
        if (!$k) continue;
        $hs = $health[$hk] ?? ['state' => 'ok'];
        if ($hs['state'] === 'dead' || ($hs['state'] === 'cooldown' && ($hs['until'] ?? 0) > time())) continue;
        $r = r_fire($k, 'session-command', $runId);
        r_log("session-command-fire {$runId} via {$hk} http={$r['code']}");
        $health[$hk] = ['state' => 'ok', 'last_code' => $r['code'], 'at' => time()];
        if ($r['code'] === 429) $health[$hk] = ['state' => 'cooldown', 'until' => time() + max($r['retry'], 3600), 'last_code' => 429, 'at' => time()];
        r_write_json('keys-health.json', $health);
        if ($r['code'] >= 200 && $r['code'] < 300) { sc_state($base + ['state' => 'fired', 'session' => $r['session']]); return; }
        sc_state($base + ['state' => 'failed', 'error' => $r['code'] === 429 ? 'usage cap of the account' : 'Claude answered HTTP ' . $r['code']]);
        return;                                             // one account, one try: never work around a cap or a refusal
    }
    sc_state($base + ['state' => 'failed', 'error' => 'no healthy dispatcher key on the host']);
}

// ── پیگیریِ دفترِ زنده روی خودِ هاست (۱۴۰۵-۰۸-۱۷ · ردیف: progress-follow-up-was-scheduled-on-the-claude-account) ──
// پیگیریِ «بسته تا کدام گام رفت» کارِ هاست است، نه یادآورِ روی اکانتِ کلاد. هر ۵ دقیقه، برای هر کارِ شلیک‌شده در
// ۶ ساعتِ گذشته، live-sessions/<task>/state.json را از main می‌خواند و هر تغییرِ stage/status را یک بار به تلگرامِ
// کنترل می‌فرستد. پایان: done/failed/waiting-user. شلیکی که ۴۵ دقیقه دفتر را برنداشت یک هشدار می‌گیرد.
function sc_read_main(string $path): ?array {
    $tok = r_secret('GITHUB_READ_TOKEN');
    if ($tok === '') return null;
    $ch = curl_init('https://api.github.com/repos/' . R_PRIVATE_REPO . '/contents/' . str_replace('%2F', '/', rawurlencode($path)) . '?ref=main');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'routine-host',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Accept: application/vnd.github.raw+json']]);
    $b = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($b)) return null;      // خواندنِ ناموفق «نامعلوم» است، نه «تغییری نیست»؛ نوبتِ بعد دوباره
    $j = json_decode($b, true);
    return is_array($j) ? $j : null;
}

function r_session_command_watch(): void {
    $fs = @json_decode((string) @file_get_contents(f_control_var() . '/sc-fire-state.json'), true) ?: [];
    $w = r_read_json('sc-watch.json');
    $now = time();
    foreach ($fs as $task => $s) {
        if (($s['state'] ?? '') !== 'fired' || $now - (int) ($s['at'] ?? 0) > 6 * 3600) continue;
        if (!preg_match('/^(b[0-9]{1,8}|t-[a-z0-9-]{1,40})$/', (string) $task)) continue;
        $run = (string) ($s['run'] ?? '');
        $seen = $w[$task] ?? [];
        if (($seen['run'] ?? '') !== $run) $seen = ['run' => $run, 'sig' => '', 'closed' => false, 'stale_sent' => false];
        if (!empty($seen['closed'])) continue;
        $st = sc_read_main("Memory-Workflow/live-sessions/{$task}/state.json");
        if ($st === null) { $w[$task] = $seen; continue; }
        $upd = strtotime((string) ($st['updated_utc'] ?? '')) ?: 0;
        $status = (string) ($st['status'] ?? '');
        if ($upd < (int) ($s['at'] ?? 0)) {                 // دفتر هنوز مالِ پیش از این شلیک است
            if (empty($seen['stale_sent']) && $now - (int) ($s['at'] ?? 0) > 45 * 60) {
                r_notify("⏳ «{$task}»: سشنِ {$run} ۴۵ دقیقه است دفتر را برنداشته (وضعِ قبلی: {$status}).");
                $seen['stale_sent'] = true;
            }
            $w[$task] = $seen;
            continue;
        }
        $sig = $status . '|' . ($st['stage'] ?? '');
        if ($sig !== ($seen['sig'] ?? '')) {
            $icon = ['done' => '✅', 'failed' => '⛔', 'waiting-user' => '🟡', 'running' => '▶️', 'idle' => '⏸'][$status] ?? '📍';
            r_notify("{$icon} «{$task}» — {$status} · گام: " . ($st['stage'] ?? '?') . "\n" . mb_substr((string) ($st['cursor'] ?? ''), 0, 300));
            $seen['sig'] = $sig;
        }
        if (in_array($status, ['done', 'failed', 'waiting-user'], true)) $seen['closed'] = true;
        $w[$task] = $seen;
    }
    foreach ($w as $t => $v) if (!isset($fs[$t])) unset($w[$t]);
    r_write_json('sc-watch.json', $w);
}
