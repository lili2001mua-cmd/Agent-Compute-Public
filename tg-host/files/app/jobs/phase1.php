<?php
// Phase-1 jobs. Each gets ($args, $hb) where $hb(array $patch) writes a heartbeat into the job status.

function job_ping(array $a, callable $hb): array { return ['pong' => true, 'at' => time()]; }

function job_probe(array $a, callable $hb): array {
    $dis = array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions')))));
    $fn = [];
    foreach (['stream_socket_client', 'stream_socket_server', 'fsockopen', 'socket_create', 'stream_select', 'proc_open',
              'exec', 'curl_multi_init', 'sodium_crypto_sign_verify_detached', 'imagecreatefromjpeg', 'random_bytes'] as $f) {
        $fn[$f] = function_exists($f) && !in_array($f, $dis, true);
    }
    $ext = [];
    foreach (['gd', 'sodium', 'phar', 'zip', 'sqlite3', 'pdo', 'mbstring', 'gmp', 'bcmath', 'sockets', 'openssl', 'zlib',
              'xml', 'dom', 'filter', 'fileinfo', 'json', 'ctype', 'intl', 'curl'] as $e) { $ext[$e] = extension_loaded($e); }
    return [
        'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'int_size' => PHP_INT_SIZE,
        'max_execution_time' => ini_get('max_execution_time'), 'memory_limit' => ini_get('memory_limit'),
        'open_basedir_set' => ini_get('open_basedir') !== '', 'disabled' => $dis, 'fn' => $fn, 'ext' => $ext,
        'usage_mb' => round(tg_usage_bytes() / 1048576, 1), 'cap_mb' => TG_DISK_CAP_BYTES / 1048576,
        'secrets_has_tg_creds' => !empty(tg_secrets()['TG_CREDS']),
    ];
}

/** Long-run calibration (panel item ن۸): survive N minutes? Heartbeat every 5 s; a gap = the host killed us. */
function job_calibrate(array $a, callable $hb): array {
    $min = (int) $a['minutes']; $cpu = $a['mode'] === 'cpu';
    $t0 = microtime(true); $last = $t0; $maxGap = 0.0; $beats = 0; $x = '';
    while (($now = microtime(true)) - $t0 < $min * 60) {
        if ($cpu) { $stop = $now + 4.5; while (microtime(true) < $stop) { $x = hash('sha256', $x . 'x'); } }
        else { sleep(5); }
        $now = microtime(true);
        $maxGap = max($maxGap, $now - $last); $last = $now; $beats++;
        $hb(['phase' => 'running', 'alive_s' => (int) ($now - $t0), 'beats' => $beats, 'pid' => getmypid()]);
    }
    return ['minutes' => $min, 'mode' => $a['mode'], 'survived_s' => (int) (microtime(true) - $t0), 'beats' => $beats,
            'max_gap_s' => round($maxGap, 1), 'peak_mem_mb' => round(memory_get_peak_usage(true) / 1048576)];
}

/** A big file in an unguessable static path, to prove Range-resumed download from a Claude cloud session. TTL 2 h. */
function job_bigfile(array $a, callable $hb): array {
    $mb = (int) $a['mb'];
    if (tg_usage_bytes() + $mb * 1048576 > TG_DISK_CAP_BYTES) throw new RuntimeException('disk cap');
    $dir = TG_BASE . '/public_html/m/' . bin2hex(random_bytes(16));
    tg_dir($dir); @chmod($dir, 0755);
    $path = $dir . '/test.bin';
    $fh = fopen($path, 'wb'); $ctx = hash_init('sha256');
    for ($i = 0; $i < $mb; $i++) {
        $block = random_bytes(1048576); fwrite($fh, $block); hash_update($ctx, $block);
        if ($i % 25 === 0) $hb(['phase' => 'writing', 'mb_done' => $i]);
    }
    fclose($fh); @chmod($path, 0644);
    file_put_contents($dir . '/.expires', (string) (time() + 7200));
    return ['url_path' => substr($dir, strlen(TG_BASE . '/public_html')) . '/test.bin', 'bytes' => $mb * 1048576,
            'sha256' => hash_final($ctx), 'expires_at' => time() + 7200];
}
