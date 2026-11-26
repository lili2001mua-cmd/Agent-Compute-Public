<?php
// Live Infrastructure snapshot for My-Control-Project (app v81):
//   - Workflows (3 live buckets: timed / manual / disabled) combining GitHub Actions + routine.hohedoc.ir scheduled AI routines
//   - Processes (flows) & Tiers (roots) built live from Sys.identity/graph.json on main (zero static APK staleness)
//   - AI executor mapping per timed routine (trainer.nightly / routine.<id>) with live last run status & schedule
// Runs in CLI cron (tick.php) so sibling routine.hohedoc.ir/app/jobs.json & var/runs.json are readable outside web open_basedir.
declare(strict_types=1);

const INFRA_SCHEMA_V      = 1;
const INFRA_REFRESH_SEC   = 900;   // refresh graph.json + routine jobs every 15 minutes
const INFRA_STALE_MAX_SEC = 1800;  // 30-minute anti-staleness lock

/** Human-readable Persian schedule from routine job fields (tehran + cron_utc). */
function infra_format_schedule_fa(string $cronUtc, string $tehranHint = ''): string {
    $t = trim($tehranHint);
    $c = trim($cronUtc);
    if ($t !== '' && $t !== '—') {
        if (str_contains($t, '(')) return $t;
        if (preg_match('/^\d+\s+[\d,]+\s+\*\/2\s+\*\s+\*$/', $c)) return "یک‌روزدرمیان · ساعتِ {$t} تهران";
        if (preg_match('/^\d+\s+[\d,]+\s+\*\s+\*\s+(\d)$/', $c, $m)) {
            $days = ['یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه'];
            $d = $days[(int) $m[1]] ?? 'هفتگی';
            return "هفتگی ({$d}) · ساعتِ {$t} تهران";
        }
        if (preg_match('/^\d+\s+([\d,]+)\s+\*\s+\*\s+\*$/', $c, $m) && str_contains($m[1], ',')) {
            $cnt = count(explode(',', $m[1]));
            return "روزانه ({$cnt} نوبت) · شروعِ {$t} تهران";
        }
        return "روزانه · ساعتِ {$t} تهران";
    }
    if ($c === '') return 'زمان‌بندیِ فعال';
    if (preg_match('/^\*\/(\d+)\s+\*\s+\*\s+\*\s+\*$/', $c, $m)) return "هر {$m[1]} دقیقه";
    if (preg_match('/^0\s+\*\/(\d+)\s+\*\s+\*\s+\*$/', $c, $m)) return "هر {$m[1]} ساعت";
    return "کرون UTC: {$c}";
}

/** Map routine.hohedoc.ir runs.json state to normalized status (success|failure|running|never). */
function infra_routine_last_run(string $jobId, array $runs): array {
    $best = null;
    $bestTs = -1;
    foreach ($runs as $runId => $r) {
        if (!is_array($r) || ($r['job'] ?? '') !== $jobId) continue;
        $ts = (int) ($r['done_at'] ?? $r['fired_at'] ?? $r['slot'] ?? 0);
        if ($ts >= $bestTs) {
            $bestTs = $ts;
            $best = $r + ['run_id' => (string) $runId];
        }
    }
    if ($best === null) {
        return ['status' => 'never', 'at_utc' => null, 'note' => 'اجرایی ثبت نشده'];
    }
    $st = (string) ($best['state'] ?? '');
    $norm = match ($st) {
        'done'               => 'success',
        'fired', 'running'   => 'running',
        'missed', 'failed', 'halt', 'pack' => 'failure',
        default              => 'never',
    };
    $note = match ($st) {
        'done'    => 'موفق' . (!empty($best['via']) ? (' · ' . $best['via']) : ''),
        'fired'   => 'در حالِ اجرا' . (!empty($best['via']) ? (' · ' . $best['via']) : ''),
        'pack'    => 'بستهٔ دستی ساخته شد',
        'missed'  => 'اجرا نشد (مهلت گذشت)',
        'unknown' => 'وضعیتِ پایان نامعلوم',
        default   => ($st !== '' ? $st : 'ثبت نشده'),
    };
    return [
        'status' => $norm,
        'at_utc' => $bestTs > 0 ? gmdate('c', $bestTs) : null,
        'note'   => $note,
    ];
}

/** Resolve account display name from catalog by alias. */
function infra_account_name(string $alias, array $catalog): string {
    foreach (($catalog['accounts'] ?? []) as $a) {
        if (is_array($a) && ($a['alias'] ?? '') === $alias) {
            return (string) ($a['name'] ?? $alias);
        }
    }
    return $alias;
}

/** Pure builder: classify workflows + routines into timed / manual / disabled buckets. */
function infra_build_workflows(
    array $ghWorkflows,
    array $declaredMap,
    array $routineJobs,
    array $routineRuns,
    array $executors,
    array $catalog
): array {
    $timed = [];
    $manual = [];
    $disabled = [];

    // 1) Host-scheduled AI routines (routine.hohedoc.ir/app/jobs.json)
    foreach ($routineJobs as $job) {
        if (!is_array($job) || empty($job['id'])) continue;
        $jid = (string) $job['id'];
        $mode = (string) ($job['mode'] ?? 'live');
        $cron = (string) ($job['cron_utc'] ?? '');
        $isPlaceholderCron = ($cron === '0 0 31 2 *');
        $procKey = ($jid === 'nightly-pool') ? 'trainer.nightly' : ('routine.' . $jid);
        $assignedAlias = (string) ($executors['processes'][$procKey]['account_alias'] ?? '');
        if ($assignedAlias === '' && !empty($job['needs_claude'])) {
            $assignedAlias = 'acct-claude-main';
        }
        $execName = $assignedAlias !== '' ? infra_account_name($assignedAlias, $catalog) : 'هاستِ آلمان';
        $lr = infra_routine_last_run($jid, $routineRuns);
        $row = [
            'id'              => 'routine:' . $jid,
            'file'            => $jid,
            'name'            => $jid,
            'source'          => 'routine_host',
            'runbook'         => (string) ($job['runbook'] ?? ''),
            'owner'           => 'routine-host',
            'state'           => ($mode === 'live') ? 'active' : 'shadow',
            'expected'        => ($mode === 'live') ? 'active' : 'shadow',
            'scheduled'       => ($mode === 'live' && !$isPlaceholderCron),
            'cron_utc'        => $isPlaceholderCron ? '' : $cron,
            'schedule_fa'     => $isPlaceholderCron ? 'فراخوانیِ رویدادی / دستی' : infra_format_schedule_fa($cron, (string) ($job['tehran'] ?? '')),
            'ai_configurable' => !empty($job['needs_claude']),
            'process_key'     => $procKey,
            'executor_alias'  => $assignedAlias,
            'executor_name'   => $execName,
            'last_status'     => $lr['status'],
            'last_at_utc'     => $lr['at_utc'],
            'last_note'       => $lr['note'],
            'retired_to'      => null,
            'reason'          => (string) ($job['runbook'] ?? ''),
        ];
        if ($mode === 'live' && !$isPlaceholderCron) {
            $timed[] = $row;
        } else {
            $manual[] = $row;
        }
    }

    // 2) GitHub Actions workflows (from live status.json + workflow-state.yaml)
    foreach ($ghWorkflows as $w) {
        if (!is_array($w) || empty($w['file'])) continue;
        $file = (string) $w['file'];
        $dec = is_array($declaredMap[$file] ?? null) ? $declaredMap[$file] : [];
        $state = (string) ($w['state'] ?? ($dec['expected'] ?? 'active'));
        $expected = (string) ($w['expected'] ?? ($dec['expected'] ?? 'active'));
        $retiredTo = $w['retired_to'] ?? ($dec['retired_to'] ?? null);
        $scheduled = (bool) ($dec['scheduled'] ?? false);
        $lr = is_array($w['last_run'] ?? null) ? $w['last_run'] : null;
        $lastStatus = 'never';
        $lastNote = 'اجرایی ثبت نشده';
        if ($lr !== null) {
            $st = (string) ($lr['status'] ?? '');
            $con = (string) ($lr['conclusion'] ?? '');
            if ($st !== 'completed') { $lastStatus = 'running'; $lastNote = 'در حالِ اجرا'; }
            elseif ($con === 'success') { $lastStatus = 'success'; $lastNote = 'موفق'; }
            elseif ($con === 'failure' || $con === 'timed_out') { $lastStatus = 'failure'; $lastNote = 'ناموفق'; }
            elseif ($con === 'cancelled' || $con === 'skipped') { $lastStatus = 'never'; $lastNote = 'لغو یا ردشده'; }
        }
        $isActive = ($state === 'active') && !($retiredTo !== null && ($dec['retire_mode'] ?? '') === 'if-false');
        $row = [
            'id'              => 'gh:' . $file,
            'file'            => $file,
            'name'            => (string) ($w['name'] ?? $file),
            'source'          => 'github_actions',
            'runbook'         => '.github/workflows/' . $file,
            'owner'           => (string) ($dec['owner'] ?? ''),
            'state'           => $state,
            'expected'        => $expected,
            'scheduled'       => $scheduled,
            'cron_utc'        => '',
            'schedule_fa'     => $scheduled ? 'زمان‌بندیِ گیت‌هاب اکشنز' : 'اجرای دستی / رویدادی',
            'ai_configurable' => false,
            'process_key'     => '',
            'executor_alias'  => '',
            'executor_name'   => 'گیت‌هاب اکشنز (اسکریپت)',
            'last_status'     => $lastStatus,
            'last_at_utc'     => $lr['at'] ?? null,
            'last_note'       => $lastNote,
            'retired_to'      => $retiredTo,
            'reason'          => (string) ($dec['reason'] ?? ''),
        ];
        if (!$isActive) {
            $disabled[] = $row;
        } elseif ($scheduled) {
            $timed[] = $row;
        } else {
            $manual[] = $row;
        }
    }

    return [
        'timed'    => $timed,
        'manual'   => $manual,
        'disabled' => $disabled,
    ];
}

/** Pure builder: parse Sys.identity/graph.json into live flows (فرایندها) and tiers (رده‌ها). */
function infra_build_graph_views(array $graph): array {
    $tiers = [];
    foreach (($graph['roots'] ?? []) as $r) {
        if (!is_array($r)) continue;
        $subs = [];
        foreach (($r['subs'] ?? []) as $s) {
            if (is_array($s) && !empty($s['title'])) $subs[] = (string) $s['title'];
            elseif (is_string($s) && $s !== '') $subs[] = $s;
        }
        $tiers[] = [
            'title' => (string) ($r['title'] ?? ''),
            'why'   => (string) ($r['why'] ?? ''),
            'subs'  => $subs,
        ];
    }

    $nodes = [];
    foreach (($graph['nodes'] ?? []) as $n) {
        if (is_array($n) && !empty($n['id'])) $nodes[(string) $n['id']] = $n;
    }
    $byTo = [];
    $consumes = [];
    foreach (($graph['edges'] ?? []) as $e) {
        if (!is_array($e)) continue;
        $type = (string) ($e['type'] ?? '');
        $from = (string) ($e['from'] ?? '');
        $to   = (string) ($e['to'] ?? '');
        if ($from === '' || $to === '') continue;
        if ($type === 'procedure') $byTo[$to][] = $from;
        elseif ($type === 'consumer') $consumes[$to][] = $from;
    }

    $flows = [];
    foreach ($nodes as $id => $n) {
        if (($n['type'] ?? '') !== 'procedure') continue;
        $members = array_values(array_unique($byTo[$id] ?? []));
        sort($members, SORT_STRING);
        $orch = [];
        $execs = [];
        $bankSet = [];
        foreach ($members as $m) {
            if (($nodes[$m]['kind'] ?? '') === 'orchestrator') $orch[] = $m;
            else $execs[] = $m;
            foreach (($consumes[$m] ?? []) as $b) {
                $parts = explode(':', $b, 2);
                $bankSet[end($parts)] = true;
            }
        }
        $banks = array_keys($bankSet);
        sort($banks, SORT_STRING);
        $rawTitle = str_contains($id, ':') ? explode(':', $id, 2)[1] : $id;
        $title = str_replace(['procedure-', '-'], ['', ' '], $rawTitle);
        $flows[] = [
            'title'         => trim($title),
            'path'          => (string) ($n['path'] ?? $id),
            'orchestrators' => $orch,
            'agents'        => $execs,
            'banks'         => $banks,
            'workflows'     => [],
        ];
    }
    usort($flows, fn($a, $b) => strcmp($a['title'], $b['title']));

    return ['flows' => $flows, 'tiers' => $tiers];
}

/** Parse Sys.registry/workflow-state.yaml text into file => metadata map. */
function infra_parse_workflow_state_yaml(string $yaml): array {
    $out = [];
    if (preg_match_all('/^  - file: (\S+)\n((?:    .*\n?)+)/m', $yaml, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            $body = $row[2];
            preg_match('/^    expected_state: (\S+)/m', $body, $e);
            preg_match('/^    retired_to: (\S+)/m', $body, $rt);
            preg_match('/^    retire_mode: (\S+)/m', $body, $rm);
            preg_match('/^    scheduled: (\S+)/m', $body, $sc);
            preg_match('/^    reason: "(.*)"/m', $body, $rs);
            $out[$row[1]] = [
                'expected'    => $e[1] ?? 'active',
                'retired_to'  => $rt[1] ?? null,
                'retire_mode' => $rm[1] ?? null,
                'scheduled'   => (($sc[1] ?? '') === 'true'),
                'reason'      => isset($rs[1]) ? mb_substr($rs[1], 0, 260) : '',
            ];
        }
    }
    return $out;
}

/** CLI cron entry point (called from tick.php): builds var/infra.json with anti-staleness lock. */
function infra_tick(bool $force = false): array {
    $prev = ctl_json_read('infra.json', null);
    if (!$force && is_array($prev) && ($prev['schema_v'] ?? 0) === INFRA_SCHEMA_V && (time() - (int) ($prev['at'] ?? 0)) < INFRA_REFRESH_SEC) {
        return $prev;
    }
    $lk = @fopen(ctl_var('infra.lock'), 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return is_array($prev) ? $prev : ['ok' => false, 'error' => 'locked'];
    try {
        $status = ctl_json_read('status.json', []) ?: [];
        $ghWfs = is_array($status['workflows'] ?? null) ? $status['workflows'] : [];
        $yaml = function_exists('gh_file') ? (gh_file('Sys.registry/workflow-state.yaml') ?? '') : '';
        $declared = infra_parse_workflow_state_yaml($yaml);

        // Read sibling routine.hohedoc.ir/app/jobs.json + var/runs.json (CLI outside web open_basedir)
        $domainsRoot = dirname(dirname(CTL_ROOT));
        $routineDir = $domainsRoot . '/routine.hohedoc.ir';
        $jobsDoc = @json_decode((string) @file_get_contents($routineDir . '/app/jobs.json'), true);
        $routineJobs = is_array($jobsDoc['jobs'] ?? null) ? $jobsDoc['jobs'] : [];
        $routineRuns = @json_decode((string) @file_get_contents($routineDir . '/var/runs.json'), true);
        if (!is_array($routineRuns)) $routineRuns = [];

        $executors = ctl_json_read('ai-process-executors.json', []) ?: [];
        $catalog = ctl_json_read('ai-accounts-catalog.json', []) ?: [];

        $wfBuckets = infra_build_workflows($ghWfs, $declared, $routineJobs, $routineRuns, $executors, $catalog);

        // Read live Sys.identity/graph.json from main
        $graphRaw = function_exists('gh_file') ? (gh_file('Sys.identity/graph.json') ?? '') : '';
        $graph = json_decode($graphRaw, true);
        $graphViews = is_array($graph) ? infra_build_graph_views($graph) : [
            'flows' => is_array($prev['flows'] ?? null) ? $prev['flows'] : [],
            'tiers' => is_array($prev['tiers'] ?? null) ? $prev['tiers'] : [],
        ];

        $out = [
            'ok'          => true,
            'schema'      => 'infra-v1',
            'schema_v'    => INFRA_SCHEMA_V,
            'at'          => time(),
            'at_utc'      => gmdate('c'),
            'max_age_sec' => INFRA_STALE_MAX_SEC,
            'counts'      => [
                'timed'           => count($wfBuckets['timed']),
                'manual'          => count($wfBuckets['manual']),
                'disabled'        => count($wfBuckets['disabled']),
                'workflows_total' => count($wfBuckets['timed']) + count($wfBuckets['manual']) + count($wfBuckets['disabled']),
                'flows'           => count($graphViews['flows']),
                'tiers'           => count($graphViews['tiers']),
            ],
            'workflows'   => $wfBuckets,
            'flows'       => $graphViews['flows'],
            'tiers'       => $graphViews['tiers'],
        ];
        ctl_json_write('infra.json', $out);
        return $out;
    } finally {
        flock($lk, LOCK_UN);
        fclose($lk);
    }
}
