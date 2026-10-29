<?php
// cleanup-spike — one-off, flag-driven removal of the MadelineProto spike leftovers (panel 1405-08-06,
// security item 5). Deletes FILES only: the imported Telegram session copy is removed from disk, never
// logged out (auth.logOut would revoke the authorization the Actions chain still uses).
// Public result public_html/cleanup.txt: counts only. Disarms itself via var/cleanup-<hash>.done.
function cleanup_rm_r(string $p, array &$n): void {
    if (is_link($p) || is_file($p)) { if (@unlink($p)) $n['files']++; return; }
    if (!is_dir($p)) return;
    foreach (scandir($p) ?: [] as $f) { if ($f !== '.' && $f !== '..') cleanup_rm_r($p . '/' . $f, $n); }
    if (@rmdir($p)) $n['dirs']++;
}
function cleanup_spike_run(): void {
    $flag = TRAINER_ROOT . '/app/cleanup.flag';
    if (!@file_exists($flag)) return;
    $done = TRAINER_ROOT . '/var/cleanup-' . substr(md5((string) @file_get_contents($flag)), 0, 10) . '.done';
    if (@file_exists($done)) return;
    @file_put_contents($done, date('c'));
    $n = ['files' => 0, 'dirs' => 0];
    $targets = ['var/mp-session', 'var/mp-madeline.log', 'var/mp-spike.log', 'var/probe-cli.done',
        'app/vendor', 'app/madeline.phar', 'app/madeline.phar.part', 'app/mp-vendor.tgz',
        'public_html/cli-env.txt', 'public_html/cli-probe2.txt', 'public_html/mp-spike.txt'];
    $gone = [];
    foreach ($targets as $t) { cleanup_rm_r(TRAINER_ROOT . '/' . $t, $n); $gone[$t] = !file_exists(TRAINER_ROOT . '/' . $t); }
    foreach (glob(TRAINER_ROOT . '/var/mp-spike-*.done') ?: [] as $f) { if (@unlink($f)) $n['files']++; }
    $r = ['at' => date('c'), 'removed_files' => $n['files'], 'removed_dirs' => $n['dirs'],
          'all_gone' => !in_array(false, $gone, true), 'session_copy_gone' => $gone['var/mp-session'], 'done' => true];
    @file_put_contents(TRAINER_ROOT . '/public_html/cleanup.txt', json_encode($r, JSON_UNESCAPED_SLASHES));
    trainer_log('cleanup', 'spike cleanup files=' . $n['files'] . ' dirs=' . $n['dirs']);
}
