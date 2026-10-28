<?php
// Cron entry, every minute: /usr/local/bin/php /home/<user>/domains/tg.hohedoc.ir/app/tick.php
// Phase 0: proves the minute clock only (writes var/last-tick.txt). The queue, lease lock and jobs
// arrive in phase 1. No Telegram code and no session here yet.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
@mkdir($root . '/var', 0700, true);
file_put_contents($root . '/var/last-tick.txt', (string) time(), LOCK_EX);
