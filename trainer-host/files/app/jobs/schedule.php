<?php
// Host-driven scheduler: the free 1-min cron is the single clock. It enables+dispatches the
// one-shot private-repo workflow when its time comes, so Actions runs only on demand. The
// crown-jewel session stays a private-repo secret inside that one-shot run; only the free clock
// lives here. Decision 1405-08-05 (/decide, 3-expert panel): option الف — session-bound compute
// stays a dispatched private one-shot ("very minimal"), host is the sole clock.
require_once __DIR__ . '/../gh.php';

/**
 * Fire nightly-pool once per day at ~03:00 Tehran (23:30 UTC). Called every minute by tick.php.
 * nightly-pool is disabled_manually on GitHub; enable it, then dispatch mode=plan. It has no
 * self-disable and no schedule (removed), so it stays active for push-triggered chain runs only —
 * zero idle Actions.
 */
function schedule_nightly(): ?array {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    // 03:00 Tehran == 23:30 UTC. Fire in a 10-minute window so a missed minute still catches.
    $mins = (int) $now->format('H') * 60 + (int) $now->format('i');
    $target = 23 * 60 + 30;
    if ($mins < $target || $mins >= $target + 10) return null;
    $stamp = trainer_state_path('nightly-fired.txt');
    $today = $now->format('Y-m-d');
    if (is_file($stamp) && trim((string) file_get_contents($stamp)) === $today) return null; // already fired
    $gh = new GitHub();
    if ($gh->workflowState('nightly-pool.yml') !== 'active') $gh->enable('nightly-pool.yml');
    $gh->dispatch('nightly-pool.yml', 'main', ['mode' => 'plan']);
    file_put_contents($stamp, $today, LOCK_EX);
    trainer_log('schedule', "dispatched nightly-pool (plan) for $today");
    return ['dispatched' => 'nightly-pool.yml', 'mode' => 'plan', 'day' => $today];
}

/**
 * Drive step3-watchdog from the host, replacing its removed 20-minute schedule. The pull job enables
 * step3-watchdog when it fires a step-3; the watchdog self-disables when no key is pending. So the
 * host only dispatches it WHILE it is active (a step-3 is genuinely pending) — zero idle cost.
 * Throttled to ~once per 20 minutes via a stamp file.
 */
function drive_step3(): ?array {
    $stamp = trainer_state_path('step3-drive.txt');
    $now = time();
    if (is_file($stamp) && ($now - (int) trim((string) file_get_contents($stamp))) < 20 * 60) return null;
    $gh = new GitHub();
    $state = $gh->workflowState('step3-watchdog.yml');
    file_put_contents($stamp, (string) $now, LOCK_EX); // stamp the check regardless, so we poll at most every 20m
    if ($state !== 'active') return null; // disabled => nothing pending => nothing to do (free)
    $gh->dispatch('step3-watchdog.yml', 'main');
    trainer_log('schedule', 'dispatched step3-watchdog (active, step-3 pending)');
    return ['dispatched' => 'step3-watchdog.yml', 'state' => $state];
}
