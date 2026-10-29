<?php
// Cron, every minute: /usr/local/bin/php /home/<user>/domains/deploy.hohedoc.ir/app/tick.php
// Polls the stylistcrm deploy queue once under one lock, and re-checks that the three sites still
// accept this host every 30 minutes. Every failure is written to state.json so status.php shows it:
// the Google channel stopped silently for weeks because an unreadable queue looked like an empty one.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
set_time_limit(0);
file_put_contents(dh_var('last-tick.txt'), (string) time());
$lock = fopen(dh_var('tick.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;   // an earlier tick is still deploying

try {
    $ping = dh_state()['ping']['at'] ?? '';
    if ($ping === '' || time() - strtotime($ping) > 1800) dh_ping();
    dh_poll();
    dh_state_set('last_ok', gmdate('c'));
    dh_state_set('last_error', null);
} catch (Throwable $e) {
    dh_state_set('last_error', ['at' => gmdate('c'), 'msg' => substr($e->getMessage(), 0, 200)]);
    dh_log('error ' . substr($e->getMessage(), 0, 200));
}
