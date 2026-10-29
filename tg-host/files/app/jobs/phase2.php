<?php
// Phase-2 jobs: Telegram login on THIS host — a fresh session of its own (never imported, never logout()).
// Session lifecycle (manifest ن۳/ن۱۰): the login runs in var/login/. Only once getSelf succeeds does the next
// tick (before any library loads, so the session is fully serialized and unlocked) move it to var/session/.
// The moment var/session/ exists, deploy.php accepts only a SHA the user pinned (user decision 2).
// One MadelineProto instance per process: tick.php ends the tick after any job marked 'tg' => true.
// MadelineProto ships as a plain vendor tree (the cron PHP has no Phar): app/mp-vendor.tgz is unpacked into
// var/lib/ (outside the manifest's reach) whenever its sha changes. ⛔ Codes and passwords never reach a log.

const TG_MP_TGZ = TG_BASE . '/app/mp-vendor.tgz';
const TG_MP_BUDGET_S = 200;

function tg_login_dir(): string { return TG_BASE . '/var/login'; }
function tg_session_dir(): string { return TG_BASE . '/var/session'; }

/** Runs at the start of every tick, before any job: promote a finished login to var/session/. */
function tg_phase2_pre(): void {
    $login = tg_login_dir(); $sess = tg_session_dir();
    if (is_file($login . '/.logged-in') && !is_dir($sess)) {
        rename($login, $sess);
        @chmod($sess, 0700);
        tg_log('login', 'session promoted');
    }
}

/** Minimal tar.gz extractor (ustar/gnu: regular files and directories; long names via 'L'; pax entries skipped). */
function tg_untar_gz(string $tgz, string $dest): int {
    $gz = gzopen($tgz, 'rb');
    if (!$gz) throw new RuntimeException('cannot open ' . basename($tgz));
    $n = 0; $longName = null;
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
        if (str_contains($name, '..')) throw new RuntimeException('tar path escapes');
        $path = $dest . '/' . ltrim($name, '/');
        if ($type === '5') { if (!is_dir($path)) mkdir($path, 0700, true); continue; }
        if ($type !== '0' && $type !== "\0") { if ($blocks) gzread($gz, $blocks * 512); continue; }
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $out = fopen($path, 'wb');
        $left = $size;
        while ($left > 0) {
            $chunk = gzread($gz, min(65536, (int) (ceil($left / 512) * 512)));
            if ($chunk === false || $chunk === '') throw new RuntimeException('truncated tar');
            fwrite($out, substr($chunk, 0, min($left, strlen($chunk)))); $left -= min($left, strlen($chunk));
        }
        fclose($out); $n++;
    }
    gzclose($gz);
    return $n;
}

/** Unpack (when the tarball changed) and load MadelineProto in-process. */
function tg_mp_boot(): void {
    static $done = false;
    if ($done) return;
    if (!is_file(TG_MP_TGZ)) throw new RuntimeException('mp-vendor.tgz not deployed');
    $lib = tg_dir(tg_var('lib'));
    $sha = hash_file('sha256', TG_MP_TGZ);
    if (!is_file($lib . '/vendor/autoload.php') || @file_get_contents($lib . '/.tgz-sha') !== $sha) {
        tg_rm_r($lib . '/vendor');
        tg_untar_gz(TG_MP_TGZ, $lib);
        if (!is_file($lib . '/vendor/autoload.php')) throw new RuntimeException('autoload.php missing after unpack');
        file_put_contents($lib . '/.tgz-sha', $sha);
    }
    // No proc_open/stream_socket_server here: declare THIS process the worker so the session runs in-process
    // (spike runs 3-8; the vendored MTProto tolerates the unbindable IPC server).
    if (!defined('MADELINE_WORKER_TYPE')) { define('MADELINE_WORKER_TYPE', 'madeline-ipc'); define('MADELINE_WORKER_ARGS', []); }
    require_once $lib . '/vendor/autoload.php';
    $done = true;
}

/** KEY=VALUE lines (the TG_CREDS shape the Python side reads). */
function tg_creds(): array {
    $out = [];
    foreach (preg_split('/\R/', (string) (tg_secrets()['TG_CREDS'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim(trim($v), "\"'");
    }
    if (empty($out['TG_API_ID']) || empty($out['TG_API_HASH'])) throw new RuntimeException('TG_CREDS lacks TG_API_ID/TG_API_HASH');
    return $out;
}

/** Open the session at $dir and run $fn($api) under one time budget. */
function tg_mp_with(string $dir, callable $fn, int $budget = TG_MP_BUDGET_S): array {
    tg_mp_boot();
    $c = tg_creds();
    $settings = new \danog\MadelineProto\Settings;
    $settings->getAppInfo()->setApiId((int) $c['TG_API_ID'])->setApiHash($c['TG_API_HASH']);
    $settings->getLogger()->setType(\danog\MadelineProto\Logger::FILE_LOGGER)
        ->setExtra(tg_var('mp-madeline.log'))
        ->setLevel(\danog\MadelineProto\Logger::WARNING)->setMaxSize(2 * 1024 * 1024);
    $settings->getPeer()->setCacheAllPeersOnStartup(false);
    // one API per session dir per process: a tick can run several Telegram jobs on one loaded session (commission ک19/ک1)
    static $apis = [];
    $work = \Amp\async(function () use ($dir, $settings, $fn, &$apis) {
        $api = $apis[$dir] ??= new \danog\MadelineProto\API($dir, $settings);
        return $fn($api);
    });
    return $work->await(new \Amp\TimeoutCancellation($budget, 'telegram budget exhausted'));
}

function tg_auth_name(int $s): string {
    return [0 => 'NOT_LOGGED_IN', 1 => 'WAITING_CODE', -1 => 'WAITING_SIGNUP', 2 => 'WAITING_PASSWORD', 3 => 'LOGGED_IN'][$s] ?? 'UNKNOWN';
}

/** Mark the login finished (promoted next tick) once getSelf answers as a real user. */
function tg_login_finish($api): array {
    $self = $api->getSelf();
    $ok = is_array($self) && !empty($self['id']) && empty($self['bot']);
    if ($ok) file_put_contents(tg_login_dir() . '/.logged-in', (string) time());
    return ['state' => $ok ? 'LOGGED_IN' : 'SELF_FAILED', 'self_ok' => $ok, 'promote' => $ok ? 'next tick' : null];
}

/** Step 1: ask Telegram to send the login code to the phone in TG_CREDS. Always starts from an empty login dir. */
function job_login_start(array $a, callable $hb): array {
    if (is_dir(tg_session_dir())) throw new RuntimeException('a session already exists on this host');
    $phone = (string) (tg_creds()['TG_PHONE'] ?? '');
    if (!preg_match('/^\+?[0-9]{8,15}$/', $phone)) throw new RuntimeException('TG_CREDS lacks a valid TG_PHONE');
    tg_rm_r(tg_login_dir());
    tg_dir(tg_login_dir());
    $hb(['phase' => 'sending code']);
    return tg_mp_with(tg_login_dir(), function ($api) use ($phone) {
        $r = $api->phoneLogin($phone);
        return ['state' => tg_auth_name($api->getAuthorization()), 'code_via' => $r['type']['_'] ?? null,
                'next_via' => $r['next_type']['_'] ?? null, 'timeout_s' => $r['timeout'] ?? null];
    });
}

/** Step 2: the code the user received. A wrong code ends this attempt (Telegram drops the code hash): start again. */
function job_login_code(array $a, callable $hb): array {
    if (!is_dir(tg_login_dir())) throw new RuntimeException('no login in progress: run login_start');
    return tg_mp_with(tg_login_dir(), function ($api) use ($a) {
        $s = $api->getAuthorization();
        if ($s !== 1) throw new RuntimeException('not waiting for a code (state ' . tg_auth_name($s) . ')');
        $r = $api->completePhoneLogin($a['code']);
        if (($r['_'] ?? '') === 'account.password') return ['state' => 'WAITING_PASSWORD', 'has_hint' => ($r['hint'] ?? '') !== ''];
        if (($r['_'] ?? '') === 'account.needSignup') throw new RuntimeException('this phone has no Telegram account');
        return tg_login_finish($api);
    });
}

/** Step 3 (only for accounts with two-step verification). */
function job_login_password(array $a, callable $hb): array {
    if (!is_dir(tg_login_dir())) throw new RuntimeException('no login in progress: run login_start');
    return tg_mp_with(tg_login_dir(), function ($api) use ($a) {
        $s = $api->getAuthorization();
        if ($s !== 2) throw new RuntimeException('not waiting for a password (state ' . tg_auth_name($s) . ')');
        $api->complete2faLogin($a['password']);
        return tg_login_finish($api);
    });
}

/** Where the login stands — no library load, no Telegram call. */
function job_login_state(array $a, callable $hb): array {
    return ['login_dir' => is_dir(tg_login_dir()), 'login_finished' => is_file(tg_login_dir() . '/.logged-in'),
            'session' => is_dir(tg_session_dir()), 'auth_recorded' => is_file(tg_var('session-auth.json'))];
}

/** Acceptance of phase 2: the promoted session answers getSelf; record this session's authorization hash (kill switch ن۱۴). */
function job_tg_self(array $a, callable $hb): array {
    if (!is_dir(tg_session_dir())) throw new RuntimeException('no session yet');
    return tg_mp_with(tg_session_dir(), function ($api) {
        $self = $api->getSelf();
        $ok = is_array($self) && !empty($self['id']) && empty($self['bot']);
        $cur = null;
        foreach (($api->account->getAuthorizations()['authorizations'] ?? []) as $au) { if (!empty($au['current'])) { $cur = $au; break; } }
        if ($cur) tg_write_json(tg_var('session-auth.json'), ['hash' => (string) $cur['hash'], 'device' => $cur['device_model'] ?? null,
            'app' => $cur['app_name'] ?? null, 'created' => $cur['date_created'] ?? null, 'recorded_at' => time()]);
        return ['self_ok' => $ok, 'auth_recorded' => (bool) $cur, 'device' => $cur['device_model'] ?? null, 'app' => $cur['app_name'] ?? null];
    });
}
