<?php
// Run by deploy.php's cron (every 5 min), CLI only: flush the Telegram outbox, run the routine clock,
// and say "alive" once a day so that a dead cron shows up as a missing message.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/dispatch.php';
$prev = (int) @file_get_contents(r_var('last-tick.txt'));
file_put_contents(r_var('last-tick.txt'), (string) time());
file_put_contents(r_var('cli-bot.json'), json_encode(r_bot_token() !== ''));
$lock = fopen(r_var('tick.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

r_flush_outbox();
if (!$prev) r_notify('🟢 routine.hohedoc.ir نصب شد و ساعتِ روتین‌ها روی هاست راه افتاد.');
elseif (time() - $prev > 15 * 60) r_notify('⚠️ کرونِ routine.hohedoc.ir حدودِ ' . round((time() - $prev) / 60) . ' دقیقه خواب بود؛ نوبت‌های جاافتادهٔ کوتاه شلیک و بقیه بستهٔ دستی می‌شوند.');

r_probe_country();          // daily, armed or not: status.php shows where the host fires from
r_clock_tick();

// Daily "alive" at 09:xx Tehran: its absence is the alarm for a dead host.
$day = r_tehran(time(), 'Y-m-d');
$alive = r_read_json('alive.json');
if ((int) r_tehran(time(), 'G') === 9 && ($alive['day'] ?? '') !== $day) {
    $jobs = r_jobs()['jobs'] ?? [];
    $k = r_keys();
    $n = 0; foreach ($k['keys'] as $a) $n += count($a);
    r_notify("🟢 routine.hohedoc.ir زنده است — {$day}\n"
           . count($jobs) . ' روتین · ' . $n . ' کلید · شلیک: ' . (r_armed() ? 'روشن' : 'خاموش (فقط بستهٔ دستی)')
           . ' · کشورِ هاست: ' . (r_probe_country() ?: 'نامعلوم')
           . ($k['problems'] ? "\n⚠️ " . implode(' · ', $k['problems']) : ''));
    r_write_json('alive.json', ['day' => $day]);
}
flock($lock, LOCK_UN);
