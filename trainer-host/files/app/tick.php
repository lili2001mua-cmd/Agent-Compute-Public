<?php
// Cron entry, every minute: /usr/local/bin/php .../trainer.hohedoc.ir/app/tick.php
// The host's free clock. Each minute it checks whether a scheduled one-shot is due and, if so,
// dispatches it on the private repo (Actions runs only on demand). No Telegram, no session here.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
// cli-probe2 (one-off, flag-driven): can cron PHP raise its limits and survive 40s? Writes public_html/cli-probe2.txt, then disarms itself.
if (@file_exists(TRAINER_ROOT . '/app/probe-cli.flag') && !@file_exists(TRAINER_ROOT . '/var/probe-cli.done')) {
    @file_put_contents(TRAINER_ROOT . '/var/probe-cli.done', date('c'));
    $r = ['started' => date('c'), 'php' => PHP_VERSION, 'int_size' => PHP_INT_SIZE,
          'before' => ['time' => ini_get('max_execution_time'), 'mem' => ini_get('memory_limit')]];
    $r['set_time_limit_0'] = @set_time_limit(0);
    $r['ini_mem_512'] = @ini_set('memory_limit', '512M');
    $r['after'] = ['time' => ini_get('max_execution_time'), 'mem' => ini_get('memory_limit')];
    $r['ext'] = ['curl' => function_exists('curl_init'), 'openssl' => extension_loaded('openssl'), 'zlib' => extension_loaded('zlib'),
                 'dom' => extension_loaded('dom'), 'filter' => extension_loaded('filter'), 'fileinfo' => extension_loaded('fileinfo'),
                 'bcmath' => extension_loaded('bcmath'), 'gmp' => extension_loaded('gmp'), 'sockets' => extension_loaded('sockets')];
    $r['allow_url_fopen'] = (bool) ini_get('allow_url_fopen');
    $r['open_basedir'] = ini_get('open_basedir') !== '' ? 'set' : 'unset';
    $t0 = microtime(true);
    $blob = str_repeat('x', 64 * 1024 * 1024); // 64MB allocation test
    $r['alloc_64mb_ok'] = strlen($blob) === 64 * 1024 * 1024; unset($blob);
    for ($i = 0; $i < 40; $i++) { sleep(1); @file_put_contents(TRAINER_ROOT . '/public_html/cli-probe2.txt', json_encode($r + ['alive_s' => $i + 1], JSON_UNESCAPED_SLASHES)); }
    $r['survived_s'] = round(microtime(true) - $t0);
    $r['peak_mem_mb'] = round(memory_get_peak_usage(true) / 1048576);
    @file_put_contents(TRAINER_ROOT . '/public_html/cli-probe2.txt', json_encode($r + ['done' => true], JSON_UNESCAPED_SLASHES));
    exit(0);
}

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
