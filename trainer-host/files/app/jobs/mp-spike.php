<?php
// mp-spike — one-off, flag-driven feasibility test of route "ب" (committee 1405-08-06):
// run MadelineProto from the cron PHP (no exec, no IPC worker), import the EXISTING Telethon
// session (no new login), and do two read-only calls: getSelf + the 5 newest Saved Messages.
// Armed by app/mp-spike.flag, disarmed by var/mp-spike.done (written first, so overlapping ticks
// never start it twice). Runs before the tick lock so the minute clock is not held.
// Public result public_html/mp-spike.txt: booleans, counts, durations only — no id, no text,
// no secret. Private detail: var/mp-spike.log and var/mp-madeline.log.
const MP_PHAR_URL = 'https://github.com/danog/MadelineProto/releases/download/8.14.0/madeline.phar';
const MP_PHAR_SHA256 = '2fad826c93009607607302bc92543cdbf0b3c2ed960c9ed6ef697f40b8cf95c4'; // pinned 1405-08-06
const MP_BUDGET_S = 420; // whole import+read budget; the phar download has its own 600s budget

function mp_spike_public(array $r): void {
    @file_put_contents(TRAINER_ROOT . '/public_html/mp-spike.txt', json_encode($r, JSON_UNESCAPED_SLASHES));
}

/** Telethon StringSession v1: '1' + urlsafe_b64( >B dc, ip(4|16), >H port, 256s auth_key ). Accepts a base64-wrapped copy too. */
function mp_decode_telethon_string(string $s): array {
    $s = trim($s);
    if ($s === '') throw new RuntimeException('TG_SESSION empty');
    if ($s[0] !== '1' || strlen($s) < 300) {
        $d = base64_decode($s, true);
        if ($d !== false && $d !== '' && $d[0] === '1') $s = trim($d);
    }
    if ($s[0] !== '1') throw new RuntimeException('not a telethon v1 string session');
    $body = substr($s, 1);
    $ipLen = strlen($body) === 352 ? 4 : 16;
    $raw = base64_decode(strtr($body, '-_', '+/'), true);
    $want = 1 + $ipLen + 2 + 256;
    if ($raw === false || strlen($raw) !== $want) throw new RuntimeException('session length ' . ($raw === false ? 'undecodable' : strlen($raw)) . " != $want");
    return [
        'dc' => ord($raw[0]),
        'ip' => inet_ntop(substr($raw, 1, $ipLen)),
        'port' => unpack('n', substr($raw, 1 + $ipLen, 2))[1],
        'auth_key' => substr($raw, 3 + $ipLen),
    ];
}

/** KEY=VALUE lines (the TG_CREDS shape the Python side reads). */
function mp_parse_env(string $text): array {
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim(trim($v), "\"'");
    }
    return $out;
}

function mp_redact(string $m): string {
    return mb_substr(preg_replace('/[A-Za-z0-9_\-]{25,}/u', '…', $m), 0, 300);
}

function mp_spike_run(): void {
    if (!@file_exists(TRAINER_ROOT . '/app/mp-spike.flag') || @file_exists(TRAINER_ROOT . '/var/mp-spike.done')) return;
    @file_put_contents(TRAINER_ROOT . '/var/mp-spike.done', date('c'));
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $r = ['started' => date('c'), 'php' => PHP_VERSION, 'phase' => 'init', 'ext_ctype' => extension_loaded('ctype')];
    mp_spike_public($r);
    trainer_log('mp-spike', 'start');
    $t0 = microtime(true);
    try {
        // 1) the phar (29.6 MB, streamed to disk, sha256-pinned; kept for later runs)
        $phar = TRAINER_ROOT . '/app/madeline.phar';
        if (!is_file($phar) || hash_file('sha256', $phar) !== MP_PHAR_SHA256) {
            $r['phase'] = 'download'; mp_spike_public($r);
            $tmp = $phar . '.part';
            $fh = fopen($tmp, 'wb');
            if (!$fh) throw new RuntimeException('cannot open ' . basename($tmp));
            $ch = curl_init(MP_PHAR_URL);
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_FAILONERROR => true]);
            $ok = curl_exec($ch); $err = curl_error($ch); curl_close($ch); fclose($fh);
            if (!$ok) { @unlink($tmp); throw new RuntimeException('phar download: ' . $err); }
            $got = hash_file('sha256', $tmp);
            if ($got !== MP_PHAR_SHA256) { @unlink($tmp); throw new RuntimeException('phar sha256 mismatch ' . substr($got, 0, 12)); }
            rename($tmp, $phar);
            $r['downloaded'] = true;
        }
        $r['phar_mb'] = round(filesize($phar) / 1048576, 1);
        $r['t_download_s'] = round(microtime(true) - $t0);

        // 2) inputs
        $sess = mp_decode_telethon_string(trainer_secret('TG_SESSION'));
        $creds = mp_parse_env(trainer_secret('TG_CREDS'));
        if (empty($creds['TG_API_ID']) || empty($creds['TG_API_HASH'])) throw new RuntimeException('TG_CREDS lacks TG_API_ID/TG_API_HASH');
        $r['session_dc'] = $sess['dc'];
        $r['session_ipv6'] = str_contains($sess['ip'], ':');

        // 3) load
        $r['phase'] = 'load'; mp_spike_public($r);
        $t1 = microtime(true);
        require_once $phar;
        $r['t_load_s'] = round(microtime(true) - $t1, 1);
        $r['mem_after_load_mb'] = round(memory_get_usage(true) / 1048576);
        $r['release'] = \danog\MadelineProto\API::RELEASE;

        $settings = new \danog\MadelineProto\Settings;
        $settings->getAppInfo()->setApiId((int) $creds['TG_API_ID'])->setApiHash($creds['TG_API_HASH']);
        $settings->getIpc()->setSlow(true); // no IPC worker (no proc_open here): serve the session in-process
        $settings->getLogger()->setType(\danog\MadelineProto\Logger::FILE_LOGGER)
            ->setExtra(TRAINER_ROOT . '/var/mp-madeline.log')
            ->setLevel(\danog\MadelineProto\Logger::WARNING)->setMaxSize(2 * 1024 * 1024);
        $settings->getPeer()->setCacheAllPeersOnStartup(false);
        $sdir = TRAINER_ROOT . '/var/mp-session';

        // 4) import (first run) or reopen, then two read-only calls — under one budget
        $r['phase'] = 'import'; mp_spike_public($r);
        $t2 = microtime(true);
        $work = \Amp\async(function () use (&$r, $sdir, $settings, $sess, $t2) {
            $api = new \danog\MadelineProto\API($sdir, $settings);
            if (is_file($sdir . '/safe.php') && $api->getSelf()) {
                $r['reopened'] = true;
            } else {
                $api->importAuthorization([$sess['dc'] => $sess['auth_key']], $sess['dc']);
            }
            $self = $api->getSelf();
            $r['self_ok'] = is_array($self) && !empty($self['id']);
            $r['self_is_bot'] = (bool) ($self['bot'] ?? false);
            $r['t_import_s'] = round(microtime(true) - $t2, 1);
            $r['phase'] = 'read'; mp_spike_public($r);
            $t3 = microtime(true);
            $h = $api->messages->getHistory(peer: 'me', limit: 5);
            $msgs = $h['messages'] ?? [];
            $r['saved_messages_read'] = count($msgs);
            $r['newest_message_age_h'] = $msgs ? round((time() - (int) ($msgs[0]['date'] ?? time())) / 3600, 1) : null;
            $r['t_read_s'] = round(microtime(true) - $t3, 1);
            return $api;
        });
        $api = $work->await(new \Amp\TimeoutCancellation(MP_BUDGET_S, 'spike budget exhausted'));
        $r['ok'] = true; $r['phase'] = 'done';
    } catch (\Throwable $e) {
        $r['ok'] = false;
        $r['error_class'] = get_class($e);
        $r['error'] = mp_redact($e->getMessage());
        trainer_log('mp-spike', 'error ' . get_class($e) . ': ' . mp_redact($e->getMessage()) . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
    $r['peak_mem_mb'] = round(memory_get_peak_usage(true) / 1048576);
    $r['total_s'] = round(microtime(true) - $t0);
    mp_spike_public($r + ['done' => true]);
    trainer_log('mp-spike', 'end ok=' . var_export($r['ok'] ?? false, true) . ' total_s=' . $r['total_s'] . ' peak_mb=' . $r['peak_mem_mb']);
    exit(($r['ok'] ?? false) ? 0 : 1);
}
