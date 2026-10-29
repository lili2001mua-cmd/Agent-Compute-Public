<?php
// Run by deploy.php's cron (every 5 min), CLI only. For every watched routine: if its last beat is older than
// its limit, send ONE Telegram alert (on the transition to stale); when a beat comes back, send ONE recovery.
// A routine that never beat counts from the day this host first ran, so a fresh install does not alarm at once.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';
file_put_contents(ops_var('last-tick.txt'), (string) time());
file_put_contents(ops_var('cli-bot.json'), json_encode(ops_bot_token() !== ''));
$tlock = fopen(ops_var('beats.lock'), 'c');
if (!$tlock || !flock($tlock, LOCK_EX)) exit;
$s = ops_state();
$born = (int) ($s['_born'] ?? 0);
if (!$born) { $born = time(); $s['_born'] = $born; }
foreach (OPS_WATCH as $id => $h) {
    $at = (int) ($s[$id]['at'] ?? 0);
    $gap = (time() - ($at ?: $born)) / 3600;
    $stale = $gap > $h;
    $was = (bool) ($s[$id]['stale'] ?? false);
    if ($stale && !$was) {
        $msg = "⚠️ نگهبانِ ops: روتینِ «{$id}» در بازهٔ خودش اجرا نشد.\n"
             . "آخرین نشانه " . ($at ? round($gap, 1) . " ساعت پیش" : "هنوز نرسیده") . " (سقف: {$h} ساعت).\n"
             . "در claude.ai/code/routines وضعیت و آخرین اجرای همین تسک را ببین.";
        if (ops_telegram($msg)) { $s[$id]['stale'] = true; ops_log("alert $id gap=" . round($gap, 1)); }
        else ops_log("alert-failed $id");
    } elseif (!$stale && $was) {
        if (ops_telegram("✅ نگهبانِ ops: روتینِ «{$id}» دوباره اجرا شد (" . round($gap, 1) . " ساعت پیش).")) {
            $s[$id]['stale'] = false; ops_log("recovered $id");
        }
    }
}
ops_save_state($s);
flock($tlock, LOCK_UN);
