<?php
// «سشن بساز و اجرا کن» of the My-Control-Project app (user decision 1405-08-12).
// control.hohedoc.ir only drops a request file in its own var/fire-req/ (signed by the phone, biometric first).
// This cron (same hosting user, so it can read the sibling folder) fires the account's dispatcher with
// job=step3-bundle run=step3-<key>-<yyyymmddhhmm>; the dispatcher checks the registry row and runs
// Memory-Routine-Runbook/step3-bundle.md, which names the session «گامِ ۳ · key · topic» first.
// One bundle per tick; the same safety as the clock: armed + supported country + healthy key; 429 never rotates.
require_once __DIR__ . '/dispatch.php';

function f_control_var(): string { return dirname((string) realpath(R_ROOT)) . '/control.hohedoc.ir/var'; }

function f_state(array $s): void {
    $f = f_control_var() . '/fire-state.json';
    $all = @json_decode((string) @file_get_contents($f), true) ?: [];
    $all[$s['key']] = $s;
    @file_put_contents($f . '.tmp', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($f . '.tmp', 0600);
    @rename($f . '.tmp', $f);
}

function r_step3_fire(): void {
    $reqs = glob(f_control_var() . '/fire-req/*.json') ?: [];
    if (!$reqs) return;
    sort($reqs);
    $file = $reqs[0];
    $q = json_decode((string) @file_get_contents($file), true) ?: [];
    @unlink($file);                                         // one try per press; the app shows the result
    $key = (string) ($q['key'] ?? '');
    if (!preg_match('/^b[0-9]{1,8}$/', $key)) return;
    $d = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $runId = 'step3-' . $key . '-' . $d->format('YmdHi');
    $base = ['key' => $key, 'run' => $runId, 'at' => time()];
    if (!r_armed() || !r_country_ok()) { f_state($base + ['state' => 'failed', 'error' => !r_armed() ? 'firing is not armed' : 'host country not confirmed']); return; }
    $jobs = r_jobs()['jobs'] ?? [];
    $job = null; foreach ($jobs as $j) if (($j['id'] ?? '') === 'step3-bundle') $job = $j;
    if (!$job) { f_state($base + ['state' => 'failed', 'error' => 'step3-bundle missing from jobs.json']); return; }
    $keys = r_keys()['keys'];
    $health = r_read_json('keys-health.json');
    $scope = $job['account_class'];
    foreach ($job['accounts'] as $alias) {
        $k = $keys[$alias][$scope] ?? null;
        $hk = "$alias-$scope";
        if (!$k) continue;
        $hs = $health[$hk] ?? ['state' => 'ok'];
        if ($hs['state'] === 'dead' || ($hs['state'] === 'cooldown' && ($hs['until'] ?? 0) > time())) continue;
        $r = r_fire($k, 'step3-bundle', $runId);
        r_log("step3-fire {$runId} via {$hk} http={$r['code']}");
        $health[$hk] = ['state' => 'ok', 'last_code' => $r['code'], 'at' => time()];
        if ($r['code'] === 429) $health[$hk] = ['state' => 'cooldown', 'until' => time() + max($r['retry'], 3600), 'last_code' => 429, 'at' => time()];
        r_write_json('keys-health.json', $health);
        if ($r['code'] >= 200 && $r['code'] < 300) { f_state($base + ['state' => 'fired', 'session' => $r['session']]); return; }
        f_state($base + ['state' => 'failed', 'error' => $r['code'] === 429 ? 'usage cap of the account' : 'Claude answered HTTP ' . $r['code']]);
        return;                                             // one account, one try: never work around a cap or a refusal
    }
    f_state($base + ['state' => 'failed', 'error' => 'no healthy dispatcher key on the host']);
}
