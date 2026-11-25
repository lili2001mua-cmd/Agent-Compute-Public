<?php
// guard_clock.php — Host-side daily clock on ops.hohedoc.ir replacing GitHub Actions `schedule:` crons.
// User rule (1405-09-03, lock: no-github-secrets-and-host-only-routines):
//   Zero `schedule:` / `cron:` routines live on GitHub Actions; all scheduling lives on the Germany host.
// Runs once per UTC day at >= 23:30 UTC (~03:00 Tehran) from ops-host/files/app/tick.php:
//   1) daily-guard.yml on 4 holding repos — ONLY dispatched when main had >= 1 commit in the last 26h.
//   2) repo-size-monitor.yml & prune-merged-branches.yml (dry_run=true) on Agent-Project-Backup.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/stranded.php';   // ops_gh(), ops_github_token()

const OPS_GUARD_OWNER = 'lili2001mua-cmd';
const OPS_GUARD_REPOS = [
    'Agent-Project-Backup',
    'Gemini-Agent-Backup',
    'ChatGPT-Agent-Backup',
    'Grok-Agent-Backup',
];
const OPS_GUARD_UTC_HOUR = 23;
const OPS_GUARD_UTC_MIN  = 30;

function ops_guard_clock_due(array $state, ?int $now = null): bool {
    $now = $now ?? time();
    $today = gmdate('Y-m-d', $now);
    if (($state['last_date'] ?? '') === $today) return false;
    $hm = (int) gmdate('G', $now) * 60 + (int) gmdate('i', $now);
    return $hm >= (OPS_GUARD_UTC_HOUR * 60 + OPS_GUARD_UTC_MIN);
}

function ops_guard_has_recent_commits(string $owner, string $repo, int $hours = 26): bool {
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - $hours * 3600);
    [$code, $data] = ops_gh('GET', "/repos/$owner/$repo/commits?sha=main&since=" . rawurlencode($since) . "&per_page=1");
    return $code === 200 && is_array($data) && count($data) > 0;
}

function ops_guard_dispatch(string $owner, string $repo, string $workflow, array $inputs = []): array {
    $body = ['ref' => 'main'];
    if ($inputs) $body['inputs'] = $inputs;
    [$code, $resp] = ops_gh('POST', "/repos/$owner/$repo/actions/workflows/$workflow/dispatches", $body);
    return ['ok' => ($code === 204 || $code === 200), 'http' => $code];
}

function ops_guard_clock_tick(): void {
    if (ops_github_token() === '') return;
    $file = ops_var('guard-clock.json');
    $state = @json_decode((string) @file_get_contents($file), true) ?: [];
    if (!ops_guard_clock_due($state)) return;

    $today = gmdate('Y-m-d');
    $res = ['last_date' => $today, 'ran_at' => gmdate('c'), 'dispatched' => [], 'skipped_idle' => [], 'errors' => []];

    foreach (OPS_GUARD_REPOS as $repo) {
        if (!ops_guard_has_recent_commits(OPS_GUARD_OWNER, $repo, 26)) {
            $res['skipped_idle'][] = "$repo/daily-guard.yml";
            continue;
        }
        $r = ops_guard_dispatch(OPS_GUARD_OWNER, $repo, 'daily-guard.yml');
        if ($r['ok']) $res['dispatched'][] = "$repo/daily-guard.yml";
        else $res['errors'][] = "$repo/daily-guard.yml:http={$r['http']}";
    }

    // Main repo daily upkeep workflows (dispatched from Germany host, zero GitHub schedule)
    foreach ([
        ['repo-size-monitor.yml', []],
        ['prune-merged-branches.yml', ['dry_run' => 'true']],
    ] as [$wf, $inp]) {
        $r = ops_guard_dispatch(OPS_GUARD_OWNER, 'Agent-Project-Backup', $wf, $inp);
        if ($r['ok']) $res['dispatched'][] = "Agent-Project-Backup/$wf";
        else $res['errors'][] = "Agent-Project-Backup/$wf:http={$r['http']}";
    }

    file_put_contents($file . '.tmp', json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($file . '.tmp', $file);
    ops_log('guard-clock dispatched=' . count($res['dispatched']) . ' skipped=' . count($res['skipped_idle']) . ' err=' . count($res['errors']));
}
