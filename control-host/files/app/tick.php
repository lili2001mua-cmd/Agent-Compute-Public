<?php
// Status tick — run after deploy.php by the same 5-minute cron. Builds var/status.json:
// every workflow of the private repo with its live state and its latest run, plus the declared
// expected state from Sys.registry/workflow-state.yaml. The app reads only this cached file, so
// the phone never costs a GitHub request and never holds a GitHub token.
require_once __DIR__ . '/lib.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$vault = ctl_vault_sync();
if (!ctl_secrets_loaded()) { ctl_json_write('status.json', ['at' => time(), 'error' => 'secrets missing (vault: ' . $vault . ')']); return; }

$lock = fopen(ctl_var('tick.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;

[$c1, $wf] = gh('GET', '/repos/' . CTL_REPO . '/actions/workflows?per_page=100');
[$c2, $runs] = gh('GET', '/repos/' . CTL_REPO . '/actions/runs?per_page=100');
if ($c1 !== 200) {
    ctl_json_write('status.json', ['at' => time(), 'error' => 'github ' . $c1]);
    ctl_log("tick github $c1");
    return;
}
$last = [];
foreach (($runs['workflow_runs'] ?? []) as $r) {
    $id = $r['workflow_id'] ?? 0;
    if (!isset($last[$id])) {
        $last[$id] = ['status' => $r['status'] ?? null, 'conclusion' => $r['conclusion'] ?? null,
                      'at' => $r['created_at'] ?? null, 'event' => $r['event'] ?? null];
    }
}
// declared expected states (text parse of the registry rows "  - file: X" + "    expected_state: Y")
$declared = [];
$yaml = gh_file('Sys.registry/workflow-state.yaml') ?? '';
if (preg_match_all('/^  - file: (\S+)\n((?:    .*\n)+)/m', $yaml, $m, PREG_SET_ORDER)) {
    foreach ($m as $row) {
        preg_match('/^    expected_state: (\S+)/m', $row[2], $e);
        preg_match('/^    retired_to: (\S+)/m', $row[2], $rt);
        $declared[$row[1]] = ['expected' => $e[1] ?? null, 'retired_to' => $rt[1] ?? null];
    }
}
$out = [];
foreach (($wf['workflows'] ?? []) as $w) {
    $file = basename((string) ($w['path'] ?? ''));
    $out[] = ['file' => $file, 'name' => $w['name'] ?? $file, 'state' => $w['state'] ?? null,
              'expected' => $declared[$file]['expected'] ?? 'active',
              'retired_to' => $declared[$file]['retired_to'] ?? null,
              'last_run' => $last[$w['id'] ?? 0] ?? null];
}
ctl_json_write('status.json', ['at' => time(), 'repo' => CTL_REPO, 'workflows' => $out]);
flock($lock, LOCK_UN);
