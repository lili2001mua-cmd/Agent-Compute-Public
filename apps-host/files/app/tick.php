<?php
// Cron, every minute: /usr/local/bin/php /home/<user>/domains/apps.hohedoc.ir/app/tick.php
// Runs queued jobs one after another for up to ~50 s under one lock (two ticks never take the same job),
// then cleans expired outputs. CLI only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/jobs.php';
set_time_limit(0);
ini_set('memory_limit', '512M');
file_put_contents(apps_var('last-tick.txt'), (string) time());
foreach (['secrets.php', 'secrets-extra.php'] as $f) if (is_file(APPS_ROOT . "/$f")) @chmod(APPS_ROOT . "/$f", 0600);   // no manual chmod step
$lock = fopen(apps_var('tick.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;   // a long job from an earlier tick is still running
$start = time();
while (time() - $start < 50 && queue_run_one() !== null) {}
queue_cleanup();
