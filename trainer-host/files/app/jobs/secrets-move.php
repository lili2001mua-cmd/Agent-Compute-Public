<?php
// secrets-move — one-off, flag-driven (security panel item 5, 1405-08-06; the user asked not to edit
// secret files by hand). On the trainer host it removes TG_SESSION (the live Actions session key,
// no longer used here) and moves TG_CREDS into the tg subdomain's secrets.php (same account,
// outside public_html). Every rewritten file is re-read and checked; on any failure the original is
// restored. Public result public_html/secrets-move.txt: booleans and counts only, never a value.
function secrets_move_write(string $path, array $data): bool {
    $php = "<?php\n// Rewritten by app/jobs/secrets-move.php on " . gmdate('c') . "\nreturn " . var_export($data, true) . ";\n";
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    $check = @include $tmp;
    if (!is_array($check) || $check !== $data) { @unlink($tmp); return false; }
    return @rename($tmp, $path);
}
function secrets_move_run(): void {
    $flag = TRAINER_ROOT . '/app/secrets-move.flag';
    if (!@file_exists($flag)) return;
    $done = TRAINER_ROOT . '/var/secrets-move-' . substr(md5((string) @file_get_contents($flag)), 0, 10) . '.done';
    if (@file_exists($done)) return;
    @file_put_contents($done, date('c'));
    $tgDest = dirname((string) realpath(TRAINER_ROOT)) . '/tg.hohedoc.ir/secrets.php'; // TRAINER_ROOT ends in /app/.. — resolve first
    $r = ['at' => date('c'), 'files_checked' => 0, 'files_rewritten' => 0, 'session_removed' => 0,
          'creds_found' => false, 'creds_moved' => false, 'tg_dir_exists' => is_dir(dirname($tgDest)), 'errors' => 0];
    $creds = null;
    $files = [TRAINER_ROOT . '/secrets.php'];
    foreach (glob(TRAINER_ROOT . '/secrets-extra*.php') ?: [] as $f) $files[] = $f;
    // 1) find TG_CREDS first, write it to tg BEFORE removing it anywhere (never lose it)
    foreach ($files as $f) {
        if (!is_file($f)) continue;
        $a = @include $f;
        if (is_array($a) && !empty($a['TG_CREDS'])) { $creds = (string) $a['TG_CREDS']; }
    }
    if ($creds !== null) {
        $r['creds_found'] = true;
        if ($r['tg_dir_exists']) {
            $cur = is_file($tgDest) ? (@include $tgDest) : [];
            if (!is_array($cur)) $cur = [];
            $cur['TG_CREDS'] = $creds;
            $r['creds_moved'] = secrets_move_write($tgDest, $cur);
            if (!$r['creds_moved']) $r['errors']++;
        }
    }
    // 2) strip TG_SESSION everywhere; strip TG_CREDS only if it is safely in tg now
    foreach ($files as $f) {
        if (!is_file($f)) continue;
        $r['files_checked']++;
        $orig = (string) @file_get_contents($f);
        $a = @include $f;
        if (!is_array($a)) { $r['errors']++; continue; }
        $n = $a;
        if (array_key_exists('TG_SESSION', $n)) { unset($n['TG_SESSION']); $r['session_removed']++; }
        if ($r['creds_moved'] && array_key_exists('TG_CREDS', $n)) unset($n['TG_CREDS']);
        if ($n === $a) continue;
        if (secrets_move_write($f, $n)) { $r['files_rewritten']++; }
        else { @file_put_contents($f, $orig, LOCK_EX); $r['errors']++; }
    }
    $after = [];
    foreach ($files as $f) { if (is_file($f)) { $a = @include $f; if (is_array($a)) $after += $a; } }
    $r['trainer_has_session_now'] = !empty($after['TG_SESSION']);
    $r['trainer_has_creds_now'] = !empty($after['TG_CREDS']);
    $r['done'] = true;
    @file_put_contents(TRAINER_ROOT . '/public_html/secrets-move.txt', json_encode($r, JSON_UNESCAPED_SLASHES));
    trainer_log('secrets-move', 'session_removed=' . $r['session_removed'] . ' creds_moved=' . var_export($r['creds_moved'], true) . ' errors=' . $r['errors']);
}
