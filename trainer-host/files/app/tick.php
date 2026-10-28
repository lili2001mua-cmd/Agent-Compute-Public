<?php
// Cron entry, every minute: /usr/local/bin/php .../trainer.hohedoc.ir/app/tick.php
// The host's free clock. Each minute it checks whether a scheduled one-shot is due and, if so,
// dispatches it on the private repo (Actions runs only on demand). No Telegram, no session here.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
// cli-env: record the cron (CLI) PHP limits so they can be read over HTTP (no secret; booleans/numbers only).
@file_put_contents(TRAINER_ROOT . '/public_html/cli-env.txt', json_encode([
    'sapi' => PHP_SAPI,
    'php' => PHP_VERSION,
    'max_execution_time' => ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit'),
    'disable_functions_count' => count(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))))),
    'exec_available' => function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true),
    'ext_gmp' => extension_loaded('gmp'), 'ext_bcmath' => extension_loaded('bcmath'),
    'ext_pcntl' => extension_loaded('pcntl'), 'ext_sockets' => extension_loaded('sockets'),
    'at' => date('c'),
], JSON_UNESCAPED_SLASHES));

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
