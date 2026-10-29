<?php
// Cron entry, every minute: /usr/local/bin/php .../trainer.hohedoc.ir/app/tick.php
// The host's free clock. Each minute it checks whether a scheduled one-shot is due and, if so,
// dispatches it on the private repo (Actions runs only on demand). No Telegram, no session here.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
// cleanup-spike (one-off, flag-driven; see jobs/cleanup-spike.php): removes the MadelineProto spike leftovers.
require __DIR__ . '/jobs/cleanup-spike.php';
cleanup_spike_run();
// secrets-move (one-off, flag-driven; see jobs/secrets-move.php): TG_SESSION out, TG_CREDS to the tg subdomain.
require __DIR__ . '/jobs/secrets-move.php';
secrets_move_run();


require __DIR__ . '/jobs/schedule.php';

$lock = fopen(trainer_state_path('tick.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

file_put_contents(trainer_state_path('last-tick.txt'), (string) time(), LOCK_EX);

// The host's clock role (when armed via var/schedule-armed.txt):
//   drive_step3() — dispatch step3-watchdog only while it is active (a step-3 is pending),
//                   replacing its removed 20-minute GitHub schedule. Zero idle cost.
// ⛔ The host does NOT fire the nightly pull. The 03:07 Tehran cloud routine (trigger
//   trig_01WcV5q45DkbsjrztLqNawia) orchestrates the nightly: it runs the Claude session for
//   step 3 (which the host cannot do) and its push triggers nightly-pool -> the floor pull.
//   Having the host also fire nightly-pool would double-run the nightly (decision D4, 1405-08-06).
//   schedule_nightly() is kept in jobs/schedule.php for a possible future where the cloud routine
//   is retired, but is intentionally NOT called here.
if (trainer_secret('GH_DISPATCH_TOKEN') !== '' && is_file(trainer_state_path('schedule-armed.txt'))) {
    try { drive_step3(); }
    catch (Throwable $e) { trainer_log('schedule', 'step3 error: ' . $e->getMessage()); }
}
