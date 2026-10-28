<?php
// mp-spike — one-off, flag-driven feasibility test of route "ب" (committee 1405-08-06):
// run MadelineProto from the cron PHP (no exec, no IPC worker), import the EXISTING Telethon
// session (no new login), and do two read-only calls: getSelf + the 5 newest Saved Messages.
// Armed by app/mp-spike.flag, disarmed by var/mp-spike.done (written first, so overlapping ticks
// never start it twice). Runs before the tick lock so the minute clock is not held.
// Public result public_html/mp-spike.txt: booleans, counts, durations only — no id, no text,
// no secret. Private detail: var/mp-spike.log and var/mp-madeline.log.
// The cron PHP has no Phar extension (spike run 1, 1405-08-06), so MadelineProto ships as a plain
// composer vendor tree: app/mp-vendor.tgz (deployed by manifest, sha-pinned there) is unpacked by
// the pure-PHP untar below into app/vendor/ on first run.
const MP_VENDOR_TGZ = TRAINER_ROOT . '/app/mp-vendor.tgz';
const MP_VENDOR_DIR = TRAINER_ROOT . '/app/vendor';
const MP_BUDGET_S = 420; // whole import+read budget

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

/** Minimal tar.gz extractor (ustar/gnu: regular files and directories; long names via 'L'; pax entries skipped). */
function mp_untar_gz(string $tgz, string $dest): int {
    $gz = gzopen($tgz, 'rb');
    if (!$gz) throw new RuntimeException('cannot open ' . basename($tgz));
    $n = 0; $longName = null;
    $real = realpath($dest) ?: $dest;
    while (!gzeof($gz)) {
        $hdr = gzread($gz, 512);
        if ($hdr === false || strlen($hdr) < 512 || trim($hdr, "\0") === '') break;
        $name = rtrim(substr($hdr, 0, 100), "\0");
        $size = (int) octdec(trim(substr($hdr, 124, 12), "\0 "));
        $type = substr($hdr, 156, 1);
        $prefix = rtrim(substr($hdr, 345, 155), "\0");
        if ($prefix !== '' && substr($hdr, 257, 5) === 'ustar') $name = $prefix . '/' . $name;
        if ($longName !== null) { $name = $longName; $longName = null; }
        $blocks = (int) ceil($size / 512);
        if ($type === 'L') { $longName = rtrim(gzread($gz, $blocks * 512), "\0"); continue; }
        if ($type === 'x' || $type === 'g') { if ($blocks) gzread($gz, $blocks * 512); continue; }
        if (str_contains($name, '..')) throw new RuntimeException('tar path escapes: ' . $name);
        $path = $dest . '/' . ltrim($name, '/');
        if ($type === '5') { if (!is_dir($path)) mkdir($path, 0755, true); continue; }
        if ($type !== '0' && $type !== "\0") { if ($blocks) gzread($gz, $blocks * 512); continue; } // links etc.
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $out = fopen($path, 'wb');
        $left = $size;
        while ($left > 0) { $chunk = gzread($gz, min(65536, (int) (ceil($left / 512) * 512))); if ($chunk === false || $chunk === '') throw new RuntimeException('truncated tar at ' . $name); fwrite($out, substr($chunk, 0, min($left, strlen($chunk)))); $left -= min($left, strlen($chunk)); }
        fclose($out); $n++;
    }
    gzclose($gz);
    return $n;
}

function mp_spike_run(): void {
    $flag = TRAINER_ROOT . '/app/mp-spike.flag';
    if (!@file_exists($flag)) return;
    $done = TRAINER_ROOT . '/var/mp-spike-' . substr(md5((string) @file_get_contents($flag)), 0, 10) . '.done'; // re-arm by changing the flag text
    if (@file_exists($done)) return;
    @file_put_contents($done, date('c'));
    @unlink(TRAINER_ROOT . '/app/madeline.phar'); // leftover of spike run 1 (no Phar extension here)
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $r = ['started' => date('c'), 'php' => PHP_VERSION, 'phase' => 'init', 'ext' => []];
    foreach (['ctype', 'phar', 'zip', 'sqlite3', 'pdo', 'mbstring', 'gmp', 'bcmath', 'sockets', 'openssl', 'zlib', 'xml', 'dom', 'filter', 'fileinfo', 'json'] as $e) $r['ext'][$e] = extension_loaded($e);
    mp_spike_public($r);
    trainer_log('mp-spike', 'start');
    $t0 = microtime(true);
    try {
        // 1) the library: unpack the vendor tree once
        if (!is_file(MP_VENDOR_DIR . '/autoload.php')) {
            if (!is_file(MP_VENDOR_TGZ)) throw new RuntimeException('mp-vendor.tgz not deployed');
            $r['phase'] = 'unpack'; mp_spike_public($r);
            $r['unpacked_files'] = mp_untar_gz(MP_VENDOR_TGZ, dirname(MP_VENDOR_DIR));
            if (!is_file(MP_VENDOR_DIR . '/autoload.php')) throw new RuntimeException('autoload.php missing after unpack');
        }
        $r['t_unpack_s'] = round(microtime(true) - $t0, 1);

        // 2) inputs
        $sess = mp_decode_telethon_string(trainer_secret('TG_SESSION'));
        $creds = mp_parse_env(trainer_secret('TG_CREDS'));
        if (empty($creds['TG_API_ID']) || empty($creds['TG_API_HASH'])) throw new RuntimeException('TG_CREDS lacks TG_API_ID/TG_API_HASH');
        $r['session_dc'] = $sess['dc'];
        $r['session_ipv6'] = str_contains($sess['ip'], ':');

        // 3) load
        $r['phase'] = 'load'; mp_spike_public($r);
        $t1 = microtime(true);
        require_once MP_VENDOR_DIR . '/autoload.php';
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
