<?php
// Unit tests for control-host/files/app/infra.php (live workflows, schedules, AI executors, processes & tiers).
require_once __DIR__ . '/../files/app/infra.php';

$pass = 0; $fail = 0;
function t_ok(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else { echo "  ✗ FAIL: $msg\n"; $fail++; }
}

// 1) Schedule formatting
t_ok(str_contains(infra_format_schedule_fa('37 23,4,9 * * *', '03:07'), '۳') || str_contains(infra_format_schedule_fa('37 23,4,9 * * *', '03:07'), '3'), '1) Multi-slot daily schedule formatted');
t_ok(str_contains(infra_format_schedule_fa('47 17 */2 * *', '21:17'), 'یک‌روزدرمیان'), '2) Every-other-day schedule formatted');
t_ok(str_contains(infra_format_schedule_fa('30 2 * * *', '06:00'), 'روزانه'), '3) Daily schedule formatted');

// 2) Workflow classification into timed / manual / disabled + AI executor binding
$ghWfs = [
    ['file' => 'repo-size-guard.yml', 'name' => 'repo-size-guard', 'state' => 'active', 'expected' => 'active', 'last_run' => ['status' => 'completed', 'conclusion' => 'success', 'at' => '2026-11-26T12:00:00Z']],
    ['file' => 'scheduled-check.yml', 'name' => 'Scheduled Check', 'state' => 'active', 'expected' => 'active', 'last_run' => ['status' => 'completed', 'conclusion' => 'failure', 'at' => '2026-11-26T11:00:00Z']],
    ['file' => 'old-off.yml', 'name' => 'Old Off', 'state' => 'disabled_manually', 'expected' => 'disabled_manually', 'last_run' => null],
];
$declared = [
    'repo-size-guard.yml' => ['expected' => 'active', 'scheduled' => false, 'reason' => 'push guard'],
    'scheduled-check.yml' => ['expected' => 'active', 'scheduled' => true, 'reason' => 'cron'],
    'old-off.yml' => ['expected' => 'disabled_manually', 'scheduled' => true, 'reason' => 'off'],
];
$routineJobs = [
    ['id' => 'nightly-pool', 'runbook' => 'Memory-Routine-Runbook/nightly-pool.md', 'cron_utc' => '37 23,4,9 * * *', 'tehran' => '03:07', 'needs_claude' => true, 'mode' => 'live'],
    ['id' => 'invariants-every-other-day', 'runbook' => 'Agents-Utility/Prompts/Live/invariants-prompt.md', 'cron_utc' => '47 17 */2 * *', 'tehran' => '21:17', 'needs_claude' => true, 'mode' => 'live'],
    ['id' => 'step3-bundle', 'runbook' => 'Memory-Routine-Runbook/step3-bundle.md', 'cron_utc' => '0 0 31 2 *', 'tehran' => '—', 'needs_claude' => true, 'mode' => 'shadow'],
];
$routineRuns = [
    'nightly-pool-202611260307' => ['job' => 'nightly-pool', 'state' => 'done', 'done_at' => 1795650000, 'via' => 'acct-grok-trainer'],
    'invariants-202611252117'   => ['job' => 'invariants-every-other-day', 'state' => 'missed', 'slot' => 1795600000],
];
$executors = ['processes' => ['trainer.nightly' => ['account_alias' => 'acct-grok-trainer']]];
$catalog = ['accounts' => [
    ['alias' => 'acct-claude-main', 'name' => 'کلادِ اصلی', 'enabled' => true],
    ['alias' => 'acct-grok-trainer', 'name' => 'گروکِ ترینر', 'enabled' => true],
]];

$buckets = infra_build_workflows($ghWfs, $declared, $routineJobs, $routineRuns, $executors, $catalog);
t_ok(count($buckets['timed']) === 3, '4) Timed bucket has 2 live routines + 1 active scheduled GH workflow');
t_ok(count($buckets['manual']) === 2, '5) Manual bucket has 1 shadow routine + 1 active manual GH workflow');
t_ok(count($buckets['disabled']) === 1, '6) Disabled bucket has 1 disabled GH workflow');
t_ok($buckets['timed'][0]['executor_alias'] === 'acct-grok-trainer' && $buckets['timed'][0]['executor_name'] === 'گروکِ ترینر' && $buckets['timed'][0]['last_status'] === 'success',
    '7) nightly-pool resolves trainer.nightly -> گروکِ ترینر with success last run');
t_ok($buckets['timed'][1]['process_key'] === 'routine.invariants-every-other-day' && $buckets['timed'][1]['executor_alias'] === 'acct-claude-main' && $buckets['timed'][1]['last_status'] === 'failure',
    '8) invariants-every-other-day defaults to کلادِ اصلی and maps missed -> failure');

// 3) Graph views (processes & tiers)
$graph = [
    'roots' => [['title' => 'تولید محتوا', 'why' => 'متن و تصویر', 'subs' => [['title' => 'متن'], ['title' => 'ویدیو']]]],
    'nodes' => [
        ['id' => 'proc:procedure-ساخت-ویدیو', 'type' => 'procedure', 'path' => 'Agents-Utility/Procedures/p1.md'],
        ['id' => 'Orchestrator-Video', 'type' => 'agent', 'kind' => 'orchestrator'],
        ['id' => 'Video-Worker', 'type' => 'agent', 'kind' => 'worker'],
    ],
    'edges' => [
        ['type' => 'procedure', 'from' => 'Orchestrator-Video', 'to' => 'proc:procedure-ساخت-ویدیو'],
        ['type' => 'procedure', 'from' => 'Video-Worker', 'to' => 'proc:procedure-ساخت-ویدیو'],
        ['type' => 'consumer', 'from' => 'bank:video-specs', 'to' => 'Video-Worker'],
    ],
];
$gv = infra_build_graph_views($graph);
t_ok(count($gv['tiers']) === 1 && count($gv['tiers'][0]['subs']) === 2, '9) Tiers parsed cleanly from graph.json');
t_ok(count($gv['flows']) === 1 && $gv['flows'][0]['orchestrators'] === ['Orchestrator-Video'] && $gv['flows'][0]['banks'] === ['video-specs'],
    '10) Flows parsed cleanly with orchestrators, agents, and banks');

echo "\nSummary: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
