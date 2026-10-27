<?php
// Cron entry, every minute: /usr/local/bin/php /home/<user>/domains/trainer.hohedoc.ir/app/tick.php
// Decides which job is due and runs it under a lock. Jobs are added here one by one as they migrate.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';

$lock = fopen(trainer_state_path('tick.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

file_put_contents(trainer_state_path('last-tick.txt'), (string) time(), LOCK_EX);
// Jobs: none enabled yet.
