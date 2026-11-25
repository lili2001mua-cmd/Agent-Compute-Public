<?php
// Cron, every minute: /usr/local/bin/php /home/<user>/domains/sites.hohedoc.ir/app/tick.php
// Polls the stylistcrm deploy queue once under one lock, and re-checks that the three sites still
// accept this host every 30 minutes. Every failure is written to state.json so status.php shows it:
// the Google channel stopped silently for weeks because an unreadable queue looked like an empty one.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/vault-sync.php';
hv_sync(__DIR__ . '/..', ['GITHUB_TOKEN' => 'gh-write-host.php']);   // before the first dh_secret() call
require __DIR__ . '/jobs.php';
require __DIR__ . '/ops.php';
require __DIR__ . '/editbus.php';
require __DIR__ . '/branches.php';
require __DIR__ . '/phone-backup.php';
require __DIR__ . '/mysites.php';
require __DIR__ . '/iran_host_manifest.php';
set_time_limit(0);
file_put_contents(dh_var('last-tick.txt'), (string) time());
$lock = fopen(dh_var('tick.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;   // an earlier tick is still deploying

try {
    $ping = dh_state()['ping']['at'] ?? '';
    if ($ping === '' || time() - strtotime($ping) > 1800) dh_ping();
    dh_dumps_cleanup();
    try { dh_phone_backup(); } catch (Throwable $e) { dh_state_set('phone_backup_error', substr($e->getMessage(), 0, 200)); }
    try { dh_weekly_tg_backup(); } catch (Throwable $e) { dh_state_set('weekly_tg_backup_error', substr($e->getMessage(), 0, 200)); }   // weekly automatic DB backup to Telegram (BKP-01, zero AI)
    try { dh_iran_host_manifest_tick(); } catch (Throwable $e) { dh_state_set('iran_manifest_error', substr($e->getMessage(), 0, 200)); }   // incremental Iran-host manifest (BKP-04-03 / Step 182)
    dh_run_editbus();
    try { dh_mysites_daily(); } catch (Throwable $e) { dh_state_set('mysites_error', substr($e->getMessage(), 0, 200)); }   // daily encrypted DB backup to My-Sites
    try { dh_mysites_incremental(); } catch (Throwable $e) { dh_state_set('mysites_inc_error', substr($e->getMessage(), 0, 200)); }   // 15-min encrypted incremental backup from change_log (STY-02)
    try { dh_mysites_monthly_drill(); } catch (Throwable $e) { dh_state_set('mysites_drill_error', substr($e->getMessage(), 0, 200)); }   // monthly automated backup chain & drill report (STY-02)
    try { dh_branch_tick(); } catch (Throwable $e) { dh_state_set('branch_ops_error', substr($e->getMessage(), 0, 200)); }   // each site records its own result; never stops the queue
    dh_run_jobs();      // each job records its own error; a broken queue must not stop the weekly digest
    dh_poll();
    dh_state_set('last_ok', gmdate('c'));
    dh_state_set('last_error', null);
} catch (Throwable $e) {
    dh_state_set('last_error', ['at' => gmdate('c'), 'msg' => substr($e->getMessage(), 0, 200)]);
    dh_log('error ' . substr($e->getMessage(), 0, 200));
}
