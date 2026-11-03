<?php
// Phone-button backup of the redlilium database (My-Control-Project app → control.hohedoc.ir → here → Telegram).
// The app signs a request with its device key (biometric first); control.hohedoc.ir only drops a request file in
// its own var/backup-req/. This cron (same hosting user, so it can see the sibling folder) picks it up, runs the
// existing dbdump through the site's bridge, sends the gzip to the private "Backup Database redlilium" channel with
// the bot, deletes the local copy and writes the result back for the app to read. The dump never touches git.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/ops.php';

const PB_CHAT_ID = '-1004449841183';             // «Backup Database redlilium» (Agent-Project-Backup: Memory-Workflow/backup-channels/channels.json)
const PB_MAX_BYTES = 49 * 1024 * 1024;           // Bot API upload limit is 50 MB
define('PB_TG_API', getenv('PB_TG_API') ?: 'https://api.telegram.org');   // overridable only for the local test

function pb_control_var(): string {
    return dirname((string) realpath(DH_ROOT)) . '/control.hohedoc.ir/var';
}

function pb_bot_token(): string {
    // Borrowed from trainer's secret files, like ops and routine do (cron only; the web side cannot see them).
    $dir = dirname((string) realpath(DH_ROOT)) . '/trainer.hohedoc.ir';
    $files = array_merge([$dir . '/secrets.php'], glob($dir . '/secrets-extra*.php') ?: []);
    foreach (array_reverse($files) as $p) {
        if (!@is_file($p)) continue;
        $t = include $p;
        if (is_array($t) && !empty($t['TELEGRAM_BOT_TOKEN'])) return (string) $t['TELEGRAM_BOT_TOKEN'];
    }
    return '';
}

function pb_state(array $s): void {
    $f = pb_control_var() . '/backup-state.json';
    @file_put_contents($f . '.tmp', json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($f . '.tmp', 0600);
    @rename($f . '.tmp', $f);
}

function pb_send(string $path, string $name, string $caption): array {
    $token = pb_bot_token();
    if ($token === '') return [false, 'bot token not visible to the cron'];
    $ch = curl_init(PB_TG_API . '/bot' . $token . '/sendDocument');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600,
        CURLOPT_POSTFIELDS => ['chat_id' => PB_CHAT_ID, 'caption' => $caption,
                               'document' => new CURLFile($path, 'application/gzip', $name)]]);
    $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if ($code === 200 && !empty($j['ok'])) return [true, (int) ($j['result']['message_id'] ?? 0)];
    return [false, $err ?: ('telegram ' . $code . ' ' . substr((string) ($j['description'] ?? ''), 0, 120))];
}

/** Jalali date and Tehran time, e.g. 1405-08-12 09:37 (the holding never shows Gregorian dates to the user). */
function pb_tehran(int $t): string {
    $d = new DateTime('@' . $t); $d->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$gy, $gm, $gd] = [(int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j')];
    $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
    $jy = -1595 + 33 * intdiv($days, 12053); $days %= 12053;
    $jy += 4 * intdiv($days, 1461); $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + $days % 31; } else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + ($days - 186) % 30; }
    return sprintf('%04d-%02d-%02d %s', $jy, $jm, $jd, $d->format('H:i'));
}

function pb_size(int $b): string {
    return $b >= 1048576 ? round($b / 1048576, 1) . ' مگابایت' : max(1, (int) round($b / 1024)) . ' کیلوبایت';
}

/** One pending request per tick. */
function dh_phone_backup(): void {
    $dir = pb_control_var() . '/backup-req';
    $reqs = glob($dir . '/*.json') ?: [];
    if (!$reqs) return;
    sort($reqs);
    $file = $reqs[0];
    $req = json_decode((string) @file_get_contents($file), true) ?: [];
    @unlink($file);                                  // one try per press; the app shows the result
    $id = preg_replace('/[^a-f0-9]/', '', (string) ($req['id'] ?? '')) ?: bin2hex(random_bytes(6));
    $base = ['id' => $id, 'target' => 'redlilium-db', 'requested_at' => (int) ($req['at'] ?? time())];
    pb_state($base + ['state' => 'running', 'started_at' => time()]);
    try {
        $r = dh_dbdump(dh_sites()['stylistcrm'], 'pwa', 'phone-' . $id);
        if (empty($r['ok'])) { pb_state($base + ['state' => 'failed', 'at' => time(), 'error' => 'dump: ' . ($r['error'] ?? '?')]); return; }
        parse_str((string) parse_url((string) $r['download'], PHP_URL_QUERY), $q);
        $tok = preg_replace('/[^a-f0-9]/', '', (string) ($q['t'] ?? ''));
        $gz = dh_var('dumps') . '/' . $tok . '.gz';
        $bytes = (int) @filesize($gz);
        if ($bytes > PB_MAX_BYTES) {
            pb_state($base + ['state' => 'failed', 'at' => time(), 'bytes' => $bytes, 'error' => 'larger than the 50 MB Telegram limit']);
        } else {
            $name = 'redlilium-db-' . str_replace([' ', ':'], ['_', ''], pb_tehran(time())) . '.sql.gz';
            $cap = "بکاپِ دیتابیسِ ردلیلیوم\n" . pb_tehran(time()) . " تهران · " . pb_size($bytes) . " · " . (int) $r['tables'] . " جدول\nاز دکمهٔ اپِ مای کنترل پروجکت";
            [$ok, $msg] = pb_send($gz, $name, $cap);
            pb_state($base + ($ok ? ['state' => 'ok', 'at' => time(), 'bytes' => $bytes, 'tables' => (int) $r['tables'], 'name' => $name, 'message_id' => $msg]
                                  : ['state' => 'failed', 'at' => time(), 'bytes' => $bytes, 'error' => (string) $msg]));
        }
        @unlink($gz); @unlink(dh_var('dumps') . '/' . $tok . '.json');   // the channel is the copy; nothing stays here
        dh_log("phone-backup $id " . ($bytes ?: 0) . ' bytes');
    } catch (Throwable $e) {
        pb_state($base + ['state' => 'failed', 'at' => time(), 'error' => substr($e->getMessage(), 0, 200)]);
    }
}
