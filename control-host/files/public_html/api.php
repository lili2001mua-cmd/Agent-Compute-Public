<?php
// The only door of control.hohedoc.ir. Every call is signed by the phone (see app/lib.php ctl_auth).
//   GET  api.php?op=status                       → cached live state of every workflow
//   GET  api.php?op=ping                         → unsigned, {"ok":true,"secrets":bool} (no data)
//   POST api.php?op=toggle-request  {workflow, action: enable|disable, reason}  → challenge
//   POST api.php?op=toggle-confirm  {challenge}  → runs it: GitHub state + registry rows in one [skip ci] commit
//   POST api.php?op=backup-request  {target: redlilium-db} → one DB dump to the Telegram backup channel (sites cron runs it)
//   GET  api.php?op=backup-status                 → last backup result + pending count
//   GET  api.php?op=projects                      → «پروژه‌ها»: orchestras by category (app/projects.php, cron) — live, no app rebuild
//   GET  api.php?op=trainer-board                 → trainer project-control board (app/board.php, cron) + fire states
//   POST api.php?op=step3-fire     {key}         → routine.hohedoc.ir opens a step-3 session for that bundle
//   POST api.php?op=session-command {task, text} → writes the command into the bundle's live-session inbox on GitHub + drops a sc-req so routine.hohedoc.ir fires a session
//        (task t-intake = the «بستهٔ تازه» button: dispatches the private repo's button-chain.yml instead — no Claude session)
//   GET  api.php?op=sizes                         → «حجمِ واقعی» of repos, hosts and Drive accounts (app/sizes.php, cron: 1 h / 6 h)
//   POST api.php?op=cover          {key}         → image/jpeg: the bundle's cover frame (app/board.php board_cover_file), 404 when none
// Two steps on purpose: the phone asks, shows the summary, the user confirms again (all workflows are critical).
require __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function out(int $code, array $x): void { http_response_code($code); echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$op = (string) ($_GET['op'] ?? '');
$secrets = ctl_secrets_loaded();
if ($op === 'ping') { if (($_GET['diag'] ?? '') === 'cover') { require_once __DIR__ . '/../app/board.php'; out(200, ['ok' => true, 'photo_cover' => board_photo_cover_diag(), 'photo_cover_cron' => board_read('photo-cover-diag.json', ['at' => null])]); }
    out(200, ['ok' => true, 'secrets' => $secrets, 'device_keys' => defined('DEVICE_KEYS'), 'gh_token' => ctl_gh_token() !== null, 'vault' => ctl_json_read('vault.json', ['state' => 'tick never ran']), 'time' => time()]); }
if (!$secrets) out(503, ['ok' => false, 'error' => 'secrets missing on host: ' . (defined('DEVICE_KEYS') ? 'GitHub token (vault)' : 'secrets.php')]);

$body = (string) file_get_contents('php://input');
if (strlen($body) > 4096) out(413, ['ok' => false, 'error' => 'too large']);
$why = ctl_auth($op, $body);
if ($why !== null) { ctl_log("auth fail op=$op $why"); out(401, ['ok' => false, 'error' => $why]); }

if ($op === 'cover') {
    require_once __DIR__ . '/../app/board.php';
    $k = (string) ((json_decode($body, true) ?: [])['key'] ?? '');
    $f = board_cover_file($k);
    if ($f === null) out(404, ['ok' => false, 'error' => 'no cover']);
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=86400');
    readfile($f); exit;
}
if ($op === 'projects' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // computed every 5 minutes by the cron from the IDEA-ORCH docs on GitHub (app/projects.php); never written by a session
    $d = ctl_var('projects');
    $p = @json_decode((string) @file_get_contents($d . '/projects.json'), true);
    $st = @json_decode((string) @file_get_contents($d . '/state.json'), true);
    out(200, ['ok' => is_array($p), 'state' => $st, 'at' => $p['at'] ?? null, 'orch_groups' => $p['orch_groups'] ?? []]);
}
if ($op === 'trainer-board' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // computed every 5 minutes by the cron from the trainer's records on GitHub (app/board.php); never written by a session
    $b = @json_decode((string) @file_get_contents(ctl_var('trainer-board') . '/board.json'), true);
    $st = @json_decode((string) @file_get_contents(ctl_var('trainer-board') . '/board-state.json'), true);
    $fire = @json_decode((string) @file_get_contents(ctl_var('fire-state.json')), true);   // written by routine.hohedoc.ir
    require_once __DIR__ . '/../app/button.php';
    out(200, ['ok' => true, 'state' => $st, 'board' => $b, 'fire' => is_array($fire) ? $fire : (object) [], 'button' => btn_public()]);
}

if ($op === 'backup-status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // written by the sites.hohedoc.ir cron (app/phone-backup.php) into our var/
    $pending = count(glob(ctl_var('backup-req') . '/*.json') ?: []);
    out(200, ['ok' => true, 'pending' => $pending, 'last' => ctl_json_read('backup-state.json', null)]);
}

if ($op === 'sizes' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // measured by the cron (app/sizes.php); the phone never measures anything itself
    out(200, ['ok' => true, 'now' => time()] + ctl_json_read('sizes.json', ['error' => 'no sizes yet']));
}

if ($op === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    out(200, ['ok' => true] + ctl_json_read('status.json', ['error' => 'no status yet']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false]);
$in = json_decode($body, true);
if (!is_array($in)) out(400, ['ok' => false, 'error' => 'bad json']);

if ($op === 'toggle-request') {
    $wf = (string) ($in['workflow'] ?? '');
    $action = (string) ($in['action'] ?? '');
    $reason = trim((string) ($in['reason'] ?? ''));
    if (!preg_match('/^[a-z0-9._-]+\.ya?ml$/', $wf) || !in_array($action, ['enable', 'disable'], true)) out(400, ['ok' => false, 'error' => 'bad workflow or action']);
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) out(400, ['ok' => false, 'error' => 'reason 3-200 chars']);
    $st = ctl_json_read('status.json', []);
    $row = null;
    foreach (($st['workflows'] ?? []) as $w) if ($w['file'] === $wf) $row = $w;
    if (!$row) out(404, ['ok' => false, 'error' => 'unknown workflow']);
    if ($action === 'enable' && !empty($row['retired_to'])) out(409, ['ok' => false, 'error' => 'retired workflow — its work moved to ' . $row['retired_to'] . '; it is never re-enabled']);
    $id = bin2hex(random_bytes(12));
    $ch = ctl_json_read('challenges.json', []);
    foreach ($ch as $k => $c) if ($c['exp'] < time()) unset($ch[$k]);
    $ch[$id] = ['workflow' => $wf, 'action' => $action, 'reason' => $reason, 'exp' => time() + CTL_CHALLENGE_TTL,
                'device' => (string) $_SERVER['HTTP_X_HC_DEVICE']];
    ctl_json_write('challenges.json', $ch);
    out(200, ['ok' => true, 'challenge' => $id, 'expires_in' => CTL_CHALLENGE_TTL,
              'summary' => ['workflow' => $wf, 'action' => $action, 'reason' => $reason, 'state_now' => $row['state']]]);
}

if ($op === 'backup-request') {
    // One press = one dump of the redlilium database into the private Telegram backup channel.
    if (($in['target'] ?? '') !== 'redlilium-db') out(400, ['ok' => false, 'error' => 'unknown target']);
    $dir = ctl_var('backup-req');
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    if (glob($dir . '/*.json')) out(409, ['ok' => false, 'error' => 'a backup is already waiting']);
    $st = ctl_json_read('backup-state.json', []);
    if (($st['state'] ?? '') === 'running' && time() - (int) ($st['started_at'] ?? 0) < 1200) out(409, ['ok' => false, 'error' => 'a backup is running']);
    $id = bin2hex(random_bytes(6));
    file_put_contents($dir . '/' . time() . '-' . $id . '.json', json_encode(['id' => $id, 'target' => 'redlilium-db', 'at' => time(),
        'device' => (string) $_SERVER['HTTP_X_HC_DEVICE']]), LOCK_EX);
    ctl_log("backup-request $id");
    out(200, ['ok' => true, 'id' => $id, 'note' => 'the sites.hohedoc.ir cron picks it up within a minute']);
}

if ($op === 'step3-fire') {
    // «سشن بساز و اجرا کن»: one bundle waiting in step 3 without a session → routine.hohedoc.ir fires the
    // account's dispatcher with job=step3-bundle (Memory-Routine-Runbook/step3-bundle.md). We only drop a file.
    $key = (string) ($in['key'] ?? '');
    if (!preg_match('/^b[0-9]{1,8}$/', $key)) out(400, ['ok' => false, 'error' => 'bad key']);
    $b = @json_decode((string) @file_get_contents(ctl_var('trainer-board') . '/board.json'), true) ?: [];
    $row = null; foreach (($b['bundles'] ?? []) as $x) if (($x['key'] ?? '') === $key) $row = $x;
    // allowed: a board bundle waiting in step 3 without a session, or a queue bundle (Routine Trainer List) not yet mapped
    $ok = $row ? (($row['kind'] ?? '') === 'nightly') : isset($b['queue']['items'][$key]);
    if (!$ok) out(409, ['ok' => false, 'error' => 'bundle has a session or is not waiting in step 3']);
    $dir = ctl_var('fire-req');
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    if (glob($dir . '/*.json')) out(409, ['ok' => false, 'error' => 'a session request is already waiting']);
    $fs = @json_decode((string) @file_get_contents(ctl_var('fire-state.json')), true) ?: [];
    if (($fs[$key]['state'] ?? '') === 'fired' && time() - (int) ($fs[$key]['at'] ?? 0) < 3600) out(409, ['ok' => false, 'error' => 'a session for this bundle was started less than an hour ago']);
    file_put_contents($dir . '/' . time() . '-' . $key . '.json', json_encode(['key' => $key, 'at' => time(),
        'device' => (string) $_SERVER['HTTP_X_HC_DEVICE']]), LOCK_EX);
    // «در حال ساخت» survives a reload of the app: the request is in fire-state until routine.hohedoc.ir writes
    // fired/failed over it (user ask 1405-08-18: the button must stay «در حال ساخت» until the bundle moves on)
    $fs[$key] = ['key' => $key, 'state' => 'requested', 'at' => time()];
    $ff = ctl_var('fire-state.json');
    file_put_contents($ff . '.tmp', json_encode($fs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($ff . '.tmp', 0600);
    rename($ff . '.tmp', $ff);
    ctl_log("step3-fire request $key");
    out(200, ['ok' => true, 'key' => $key, 'note' => 'routine.hohedoc.ir fires it within 5 minutes']);
}

if ($op === 'session-command') {
    // فرمانِ مستقیمِ کاربر از نرم‌افزار: روی گیت‌هاب در دفترِ زندهٔ بسته (inbox) نوشته می‌شود (ارکستر github-backed-session-ledger).
    require_once __DIR__ . '/../app/toggle.php';
    $in = json_decode($body, true) ?: [];
    $task = (string) ($in['task'] ?? '');
    $text = trim((string) ($in['text'] ?? ''));
    if (!preg_match('/^(b[0-9]{1,8}|t-[a-z0-9-]{1,40})$/', $task)) out(400, ['ok' => false, 'error' => 'bad task']);
    if ($text === '' || mb_strlen($text) > 2000) out(400, ['ok' => false, 'error' => 'empty or too long']);
    $ts = gmdate('Ymd-His');
    $base = 'Memory-Workflow/live-sessions/' . $task;
    $path = $base . '/inbox/' . $ts . '.json';
    // ⛔ دکمهٔ «بستهٔ تازه» (t-intake) دیگر سشنِ کلاد را شلیک نمی‌کند (۱۴۰۵-۰۸-۱۸ · زنجیرهٔ مستقلِ دکمه): گام‌های ۱ و ۲ را
    // ورک‌فلوی button-chain.yml ِ مخزنِ خصوصی روی رانرِ گیت‌هاب اجرا می‌کند و گامِ ۳ را از راهِ runner.php درخواست می‌دهد.
    $button = $task === 't-intake';
    if ($button) {
        // ⛔ فشارِ دوم وقتی بستهٔ قبلی هنوز در راه است رد می‌شود (app/button.php)؛ اپ همان حالتِ در راه را نشان می‌دهد
        require_once __DIR__ . '/../app/button.php';
        [$fresh, $bs] = btn_press();
        if (!$fresh) out(409, ['ok' => false, 'error' => 'بستهٔ تازهٔ قبلی هنوز در راه است', 'button' => btn_public()]);
    }
    $files = [$path => json_encode(['text' => $text, 'at' => time(), 'device' => (string) ($_SERVER['HTTP_X_HC_DEVICE'] ?? ''), 'status' => $button ? 'button-chain' : 'pending'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    // بستهٔ تازه دفتر ندارد (op فقط inbox می‌نوشت) — state.json/TASK.md را فقط اگر نبود بذر کن تا سشن مثلِ هر بستهٔ دیگر راه بیفتد.
    $seeded = false;
    if (gh_file($base . '/state.json') === null) {
        $iso = gmdate('c');
        $files[$base . '/state.json'] = json_encode(['stage' => '', 'status' => 'idle',
            'cursor' => 'دفترِ تازه از نرم‌افزار؛ گام را از تابلو بخوان و فرمانِ inbox را اجرا کن', 'holder' => null, 'updated_utc' => $iso],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $files[$base . '/TASK.md'] = "# کارِ زنده — $task\n\n| قلم | مقدار |\n|---|---|\n| کلیدِ کار | `$task` |\n"
            . "| سازنده | نرم‌افزارِ My-Control-Project (op=session-command) |\n| ساخته‌شده | $iso |\n\n"
            . "> دفترِ زندهٔ این کار (ارکستر github-backed-session-ledger). سشن این‌جا را می‌خواند، یک گام جلو می‌رود و چک‌پوینت می‌زند.\n";
        $seeded = true;
    }
    [$ok, $sha] = ctl_commit($files, 'live-session ' . $task . ': فرمانِ مستقیم' . ($seeded ? ' + بذرِ دفتر' : '') . ' از نرم‌افزار [skip ci]');
    if (!$ok) {
        if ($button) btn_update(fn(array $x) => btn_set($x, 'failed', ['error' => 'ثبتِ فرمان روی گیت‌هاب نشد', 'note' => 'queued', 'at_stage' => 'queued']));
        out(502, ['ok' => false, 'error' => 'commit failed: ' . $sha]);
    }
    if ($button) {
        [$dc, , $draw] = gh('POST', '/repos/' . CTL_REPO . '/actions/workflows/button-chain.yml/dispatches', ['ref' => CTL_BRANCH, 'inputs' => ['mode' => 'intake']]);
        ctl_log("session-command $task -> button-chain dispatch http=$dc");
        if ($dc !== 204) {
            btn_update(fn(array $x) => btn_set($x, 'failed', ['error' => 'شروعِ زنجیره روی گیت‌هاب نشد (HTTP ' . $dc . ')', 'note' => 'queued', 'at_stage' => 'queued']));
            out(502, ['ok' => false, 'error' => 'button-chain dispatch failed: HTTP ' . $dc . ' ' . mb_substr($draw, 0, 200), 'button' => btn_public()]);
        }
        out(200, ['ok' => true, 'task' => $task, 'path' => $path, 'commit' => $sha, 'fire' => 'button-chain', 'button' => btn_public()]);
    }
    // درخواستِ آتش: routine.hohedoc.ir (session-command-fire.php) سشن را با job=session-command اجرا می‌کند.
    // coalesce: اگر برای همین task درخواستی در صف است، دوباره نگذار — سشنِ آتش‌شده همهٔ inbox را می‌خواند.
    $dir = ctl_var('sc-req');
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $pending = false;
    foreach (glob($dir . '/*.json') ?: [] as $f) { $r = @json_decode((string) @file_get_contents($f), true) ?: []; if (($r['task'] ?? '') === $task) { $pending = true; break; } }
    if (!$pending) file_put_contents($dir . '/' . time() . '-' . $task . '.json', json_encode(['task' => $task, 'at' => time(),
        'device' => (string) ($_SERVER['HTTP_X_HC_DEVICE'] ?? '')]), LOCK_EX);
    ctl_log("session-command $task <- " . mb_substr($text, 0, 40) . ($pending ? ' (fire pending)' : ' (fire queued)'));
    out(200, ['ok' => true, 'task' => $task, 'path' => $path, 'commit' => $sha, 'fire' => $pending ? 'pending' : 'queued']);
}

if ($op === 'toggle-confirm') {
    $id = (string) ($in['challenge'] ?? '');
    $ch = ctl_json_read('challenges.json', []);
    $c = $ch[$id] ?? null;
    unset($ch[$id]);
    ctl_json_write('challenges.json', $ch);
    if (!$c || $c['exp'] < time() || $c['device'] !== (string) $_SERVER['HTTP_X_HC_DEVICE']) out(410, ['ok' => false, 'error' => 'challenge expired or unknown']);
    require __DIR__ . '/../app/toggle.php';
    $r = ctl_toggle($c['workflow'], $c['action'], $c['reason']);
    ctl_log('toggle ' . $c['action'] . ' ' . $c['workflow'] . ' => ' . json_encode($r));
    out($r['ok'] ? 200 : 502, $r);
}

out(404, ['ok' => false, 'error' => 'unknown op']);
