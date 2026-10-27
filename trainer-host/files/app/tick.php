<?php
// Cron entry, every minute: /usr/local/bin/php .../trainer.hohedoc.ir/app/tick.php
// The host's free clock. Each minute it checks whether a scheduled one-shot is due and, if so,
// dispatches it on the private repo (Actions runs only on demand). No Telegram, no session here.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
require __DIR__ . '/jobs/schedule.php';

$lock = fopen(trainer_state_path('tick.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

file_put_contents(trainer_state_path('last-tick.txt'), (string) time(), LOCK_EX);

// Auto-dispatch stays OFF until one live test confirms the exact workflow mode and time.
// Flip to on by creating var/schedule-armed.txt once verified. Until then the host is a clock only.
if (trainer_secret('GH_DISPATCH_TOKEN') !== '' && is_file(trainer_state_path('schedule-armed.txt'))) {
    try { schedule_nightly(); }
    catch (Throwable $e) { trainer_log('schedule', 'error: ' . $e->getMessage()); }
}
