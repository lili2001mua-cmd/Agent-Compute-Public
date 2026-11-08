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
