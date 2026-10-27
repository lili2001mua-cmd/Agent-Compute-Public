<?php
// Host-driven scheduler: the free 1-min cron is the single clock. It dispatches the one-shot
// private-repo workflow when its time comes, so Actions runs only on demand (no self-schedule).
// Secret-using work stays in that one-shot workflow; the repeated clock lives here, free.
require_once __DIR__ . '/../gh.php';

/** Fire nightly-pool once per day at ~03:00 Tehran (23:30 UTC). Called every minute by tick.php. */
function schedule_nightly(): ?array {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    // 03:00 Tehran == 23:30 UTC. Fire in a 10-minute window so a missed minute still catches.
    $mins = (int) $now->format('H') * 60 + (int) $now->format('i');
    $target = 23 * 60 + 30;
    if ($mins < $target || $mins >= $target + 10) return null;
    $stamp = trainer_state_path('nightly-fired.txt');
    $today = $now->format('Y-m-d');
    if (is_file($stamp) && trim((string) file_get_contents($stamp)) === $today) return null; // already fired
    (new GitHub())->dispatch('nightly-pool.yml', 'main');
    file_put_contents($stamp, $today, LOCK_EX);
    trainer_log('schedule', "dispatched nightly-pool for $today");
    return ['dispatched' => 'nightly-pool.yml', 'day' => $today];
}
