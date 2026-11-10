<?php
// Run by deploy.php's cron (every 5 min), CLI only: flush the Telegram outbox, run the routine clock,
// and say "alive" once a day so that a dead cron shows up as a missing message.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/dispatch.php';
require_once __DIR__ . '/vault-sync.php';
hv_sync(R_ROOT, ['GITHUB_READ_TOKEN' => 'gh-read-host.php']);   // before the first r_secret() call
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
require_once __DIR__ . '/step3-fire.php';
try { r_step3_fire(); } catch (Throwable $e) { r_log('step3-fire ' . substr($e->getMessage(), 0, 200)); }
require_once __DIR__ . '/session-command-fire.php';
try { r_session_command_fire(); } catch (Throwable $e) { r_log('session-command-fire ' . substr($e->getMessage(), 0, 200)); }
try { r_session_command_watch(); } catch (Throwable $e) { r_log('session-command-watch ' . substr($e->getMessage(), 0, 200)); }

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
// ⛔ صفِ دکمه‌ها بین دو نوبتِ کرون (۱۴۰۵-۰۸-۱۹ · ردیف: app-command-waits-for-the-five-minute-cron): درخواستِ
//    «سشن بساز» و «فرمانِ مستقیم» تا نوبتِ بعدیِ کرون (تا ۵ دقیقه) می‌ماند و معیارِ کاربر «زیرِ ۲ دقیقه در سشن» را
//    می‌شکست. وبِ control به پوشهٔ این هاست دسترسی ندارد (open_basedir)، پس شلیکِ همان‌لحظه از وب ممکن نیست؛ همین
//    نوبت تا ۲۴۰ ثانیه پس از شروع هر ۱۰ ثانیه دو صف را نگاه می‌کند (هزینه: دو glob). قفلِ tick.lock تا پایان نگه
//    داشته می‌شود و نوبتِ بعدی (۳۰۰ ثانیه) هرگز با این یکی هم‌پوشانی ندارد.
$__t0 = (int) ($_SERVER['REQUEST_TIME'] ?? time());
$__poll = (int) (getenv('R_POLL_SECONDS') !== false ? getenv('R_POLL_SECONDS') : 240);
while (time() - $__t0 < $__poll - 10) {
    sleep(10);
    try { r_step3_fire(); } catch (Throwable $e) { r_log('step3-fire(poll) ' . substr($e->getMessage(), 0, 200)); }
    try { r_session_command_fire(); } catch (Throwable $e) { r_log('session-command-fire(poll) ' . substr($e->getMessage(), 0, 200)); }
}
flock($lock, LOCK_UN);
