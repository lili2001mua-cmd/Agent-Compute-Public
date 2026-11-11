<?php
// Host night plan (phase 8, B1) — offline self-test. Not deployed.
// Runs the REAL phase3 (grouping port), phase6 (chain `history` op, allowlist, lease read), phase7 (repo writer, with
// tg_gh stubbed) and phase8 code against a fixture history served by a fake MadelineProto API, and compares the plan
// with the Python reference (host_plan_ref.py → the REAL tg_grouping.py / telegram_pool.py / nightly_pool.py).
// Usage: php tg-host/tests/host-plan-test.php      (APB_ROOT = Agent-Project-Backup checkout, default: sibling folder)
$tmp = sys_get_temp_dir() . '/host-plan-test-' . getmypid();
@mkdir($tmp . '/app/jobs', 0777, true);
$app = __DIR__ . '/../files/app';
copy($app . '/lib.php', $tmp . '/app/lib.php');           // TG_BASE = $tmp
file_put_contents($tmp . '/app/keys.php', "<?php return [];");
require $tmp . '/app/lib.php';
require $app . '/jobs/phase3.php';
require $app . '/jobs/phase6.php';
// phase7: the real writer code, minus the network (tg_gh is stubbed below)
$p7 = file_get_contents($app . '/jobs/phase7.php');
$cut = fn($from, $to) => substr($p7, strpos($p7, $from), strpos($p7, $to) - strpos($p7, $from));
file_put_contents($tmp . '/app/jobs/p7.php', "<?php\n" . $cut('const TG_REPO =', 'const TG_BEAT_EVERY_S') . $cut('function tg_repo_allowed', '/** Hourly heartbeat'));
require $tmp . '/app/jobs/p7.php';
require $app . '/jobs/phase8.php';
$reg = include $app . '/jobs/registry.php';

$F = 0; function ok($c, $m) { global $F; echo ($c ? "  ok   " : "  FAIL ") . $m . "\n"; if (!$c) $F++; }

// ---- stubs for the library- and network-bound pieces -------------------------------------------------
$GLOBALS['gh'] = ['archived' => [], 'files' => [], 'put_code' => 201, 'puts' => 0, 'gets' => 0];
function tg_gh(string $method, string $path, ?array $body = null): array {
    $g = &$GLOBALS['gh'];
    if (preg_match('#/contents/Memory-Workflow/nightly-pool/archive/(b\d+)\.json\?ref=main$#', $path, $m)) { $g['gets']++; return [in_array($m[1], $g['archived'], true) ? 200 : 404, []]; }
    if (preg_match('#/contents/(.+?)(\?ref=main)?$#', $path, $m)) {
        $p = rawurldecode($m[1]);
        if ($method === 'GET') return isset($g['files'][$p]) ? [200, ['sha' => sha1($g['files'][$p]), 'content' => base64_encode($g['files'][$p])]] : [404, []];
        if ($method === 'PUT') { $g['puts']++; if ($g['put_code'] < 300) $g['files'][$p] = base64_decode($body['content']); return [$g['put_code'], []]; }
    }
    return [500, []];
}
function tg_session_dir(): string { return TG_BASE . '/var/session'; }
function tg_mp_with(string $dir, callable $fn, int $budget = 0): array { return $fn($GLOBALS['fake_api']); }
final class FakeMessages {
    public int $calls = 0;
    public function __construct(private array $all) {}           // newest first
    public function getHistory($peer, int $offset_id = 0, int $limit = 100): array {
        $this->calls++;
        if ($peer !== -1001234567890) throw new RuntimeException('wrong peer');
        $out = [];
        foreach ($this->all as $m) { if ($offset_id && $m['id'] >= $offset_id) continue; $out[] = $m; if (count($out) >= $limit) break; }
        return ['messages' => $out];
    }
}
final class FakeApi { public function __construct(public FakeMessages $messages) {} }

// ---- the fixture: a realistic source-chat history around the night of 2026-11-10 --------------------
$DATE = '2026-11-10';
[$S, $U] = tg_host_window($DATE);
ok(tg_host_iso($S) === '2026-11-09T03:00:00+03:30' && tg_host_iso($U) === '2026-11-10T03:00:00+03:30', 'window = [03:00 Tehran of date-1, 03:00 Tehran of date)');
$msgs = [];
$txt = function (int $id, int $t, string $s) use (&$msgs) { $msgs[] = ['_' => 'message', 'id' => $id, 'date' => $t, 'message' => $s]; };
$med = function (int $id, int $t, array $media, $gid = null, string $cap = '') use (&$msgs) {
    $m = ['_' => 'message', 'id' => $id, 'date' => $t, 'message' => $cap, 'media' => $media]; if ($gid !== null) $m['grouped_id'] = $gid; $msgs[] = $m; };
$photo = ['_' => 'messageMediaPhoto', 'photo' => ['_' => 'photo']];
$video = ['_' => 'messageMediaDocument', 'document' => ['_' => 'document', 'mime_type' => 'video/mp4', 'attributes' => [['_' => 'documentAttributeVideo'], ['_' => 'documentAttributeFilename', 'file_name' => 'v.mp4']]]];
$round = ['_' => 'messageMediaDocument', 'document' => ['_' => 'document', 'mime_type' => 'video/mp4', 'attributes' => [['_' => 'documentAttributeVideo', 'round_message' => true]]]];
$voice = ['_' => 'messageMediaDocument', 'document' => ['_' => 'document', 'mime_type' => 'audio/ogg', 'attributes' => [['_' => 'documentAttributeAudio', 'voice' => true]]]];
$pdf = ['_' => 'messageMediaDocument', 'document' => ['_' => 'document', 'mime_type' => 'application/pdf', 'attributes' => [['_' => 'documentAttributeFilename', 'file_name' => 'guide.pdf']]]];
$link = ['_' => 'messageMediaWebPage', 'webpage' => ['_' => 'webPage']];                       // bot header: "other"
$linkEmpty = ['_' => 'messageMediaWebPage', 'webpage' => ['_' => 'webPageEmpty']];
$linkPhoto = ['_' => 'messageMediaWebPage', 'webpage' => ['_' => 'webPage', 'photo' => ['_' => 'photo']]]; // Telethon: .photo
$url = 'https://www.instagram.com/p/XYZ/';
$txt(100, $S - 7200, 'old night');                      // below the floor (since - 1 h): never read
$med(101, $S - 3700, $photo);                           // below the floor
$med(102, $S - 3590, $photo);                           // read (above the floor), out of window
$txt(110, $S - 3000, 'پستِ دیروز');
for ($i = 0; $i < 3; $i++) $med(111 + $i, $S - 2990, $photo, 13800000000000001);
$txt(114, $S - 2980, 'بالا');
$med(115, $S - 8, $link, null, $url); $med(116, $S + 3, $video); // straddles since: first media before since ⇒ out
$txt(117, $S + 4, 'بالا');
$txt(130, $S + 100, 'پستِ تازه');
$med(131, $S + 200, $link, null, $url);                 // 20-slide carousel: header + full album + 3 more ⇒ one bundle
for ($i = 0; $i < 10; $i++) $med(132 + $i, $S + 201, $video, 13800000000000002);
for ($i = 0; $i < 3; $i++) $med(142 + $i, $S + 203, $video, 13800000000000003);
$txt(145, $S + 210, 'بالا');
$med(150, $S + 1000, $photo); $med(151, $S + 1010, $photo); // ≤15 s, no album ⇒ same bundle
$med(152, $S + 1040, $photo);                           // 30 s later ⇒ new bundle
$msgs[] = ['_' => 'messageService', 'id' => 153, 'date' => $S + 1050, 'action' => ['_' => 'messageActionPinMessage']];
$msgs[] = ['_' => 'messageEmpty', 'id' => 154];
$med(160, $S + 2000, $linkEmpty, null, $url);           // a link without media is not a package
$txt(161, $S + 2001, 'بالا');
$med(170, $S + 3000, $linkPhoto, null, $url);           // a link preview photo counts as a photo (Telethon)
$txt(171, $S + 3001, 'بالا');
$med(180, $S + 4000, $round); $med(181, $S + 4005, $voice); // round video + voice = documents, same bundle
$med(182, $S + 4100, $pdf);
$msgs[] = ['_' => 'message', 'id' => 183, 'date' => $S + 4101, 'message' => 'hello', 'media' => ['_' => 'messageMediaEmpty']]; // = text
for ($i = 0; $i < 3; $i++) $med(190 + $i, $S + 5000, $photo, 13800000000000004);
$txt(193, $S + 5001, 'بالا');
for ($i = 0; $i < 4; $i++) $med(400 + $i, $S + 6000, $video, 13800000000000005); // non-full album …
for ($i = 0; $i < 2; $i++) $med(404 + $i, $S + 6002, $video, 13800000000000006); // … then another album ⇒ two bundles
$txt(406, $S + 6003, 'بالا');
$txt(500, $U - 100, 'پستِ آخرِ شب');
$med(501, $U - 5, $photo);                              // in (U - 5)
$txt(502, $U - 4, 'بالا');
$med(503, $U, $video);                                  // exactly at until ⇒ out (half-open)
$txt(504, $U + 1, 'بالا');
$med(505, $U + 100, $video, null, 'after the night');   // after until ⇒ out
$ARCHIVED = ['b190', 'b404'];

$dir = $tmp . '/fx'; @mkdir($dir);
file_put_contents($dir . '/fixture.json', json_encode(['date' => $DATE, 'messages' => $msgs, 'archived' => $ARCHIVED], JSON_UNESCAPED_UNICODE));

// ---- tick: slot, once a night, internal-only job ---------------------------------------------------
ok(($reg['host_plan']['roles'] ?? null) === [] && !empty($reg['host_plan']['tg']) && !empty($reg['host_plan']['retry']), 'registry: host_plan is internal (no door role), tg, retry');
$teh = fn(string $s) => (new DateTimeImmutable($s, new DateTimeZone('+03:30')))->getTimestamp();
ok(tg_host_config()['mode'] === 'dry', 'no host-config.json ⇒ dry');
ok(tg_host_target_date($teh('2026-11-10 02:59')) === '2026-11-09' && tg_host_target_date($teh('2026-11-10 03:00')) === '2026-11-10', 'target date flips at 03:00 Tehran (nightly_pool.target_date)');
ok(tg_host_plan_tick($teh('2026-11-10 03:06')) === 'not-due', '03:06 ⇒ not due');
ok(tg_host_plan_tick($teh('2026-11-10 03:30')) === 'not-due', '03:30 ⇒ not due (slot is 03:07–03:30)');
ok(tg_host_plan_tick($teh('2026-11-10 03:07')) === 'queued', '03:07 ⇒ one host_plan job queued');
ok(tg_host_plan_tick($teh('2026-11-10 03:08')) === 'busy', 'next tick while queued ⇒ busy, no second job');
$q = glob(tg_var('queue') . '/*.json');
ok(count($q) === 1 && ($qj = tg_read_json($q[0]))['job'] === 'host_plan' && $qj['args'] === ['date' => $DATE] && $qj['role'] === 'internal', 'queue file: job host_plan, args {date}, role internal');

// the drain, as tick.php does it, against the fake API (page of 7 to exercise paging and the floor stop)
tg_write_json(tg_var('chain-chats.json'), ['chats' => ['-1001234567890' => ['kind' => 'source', 'title' => 'Instant Saver', 'label' => 'source'],
                                                      '-1009' => ['kind' => 'archive', 'title' => 'archive', 'label' => 'archive']]]);
$newest = array_reverse($msgs);
$GLOBALS['fake_api'] = new FakeApi(new FakeMessages($newest));
ok(tg_host_plan_source() === -1001234567890, 'source chat = the allowlisted kind=source chat');
$paged = tg_host_plan_collect($GLOBALS['fake_api'], -1001234567890, $S - 3600, null, 7);
$GLOBALS['gh']['archived'] = $ARCHIVED;
$run = function () use ($q) {
    $j = tg_read_json($q[0]);
    try { $r = job_host_plan($j['args'], fn($p) => null, 'internal'); tg_status($j['id'], ['state' => 'done']); return $r; }
    catch (Throwable $e) { tg_status($j['id'], ['state' => 'failed', 'error' => $e->getMessage()]); return $e; }
    finally { @unlink($q[0]); }
};
$res = $run();
ok(is_array($res), 'job_host_plan ran: ' . (is_array($res) ? 'ok' : $res->getMessage()));
$path = 'Memory-Workflow/nightly-pool/host/' . $DATE . '.json';
ok(tg_repo_allowed($path), 'output path is on the phase-7 allowlist');
$doc = json_decode($GLOBALS['gh']['files'][$path] ?? 'null', true);
ok(is_array($doc) && $doc['mode'] === 'dry' && $doc['date'] === $DATE && isset($doc['at_utc'], $doc['host_version'], $doc['since'], $doc['until']), 'plan file written: date, since, until, keys, bundles, mode=dry, at_utc, host_version');
ok(tg_host_plan_tick($teh('2026-11-10 03:09')) === 'done' && tg_host_plan_tick($teh('2026-11-10 03:20')) === 'done', 'night done ⇒ never queued again');
$GLOBALS['fake_api']->messages->calls = 0;
$full = tg_host_plan_collect($GLOBALS['fake_api'], -1001234567890, $S - 3600);
ok(array_column($paged, 'id') === array_column($full, 'id') && min(array_column($full, 'id')) === 102, 'paging (7/page) = one 3000 page; read stops at the floor (102 in, 101 out)');

// ---- parity with the Python reference ------------------------------------------------------------------
$cmd = 'python3 ' . escapeshellarg(__DIR__ . '/host_plan_ref.py') . ' ' . escapeshellarg($dir . '/fixture.json') . ' 2>&1';
exec($cmd, $lines, $rc);
$py = $rc === 0 ? json_decode(implode("\n", $lines), true) : null;
if (!is_array($py)) { echo "  FAIL python reference did not run (rc=$rc):\n" . implode("\n", $lines) . "\n"; $F++; }
else {
    echo "  php    keys: " . implode(',', $doc['keys'] ?? []) . "\n";
    echo "  python keys: " . implode(',', $py['keys']) . "\n";
    echo "  php    window_keys: " . implode(',', $doc['window_keys'] ?? []) . "\n";
    echo "  python window_keys: " . implode(',', $py['window_keys']) . "\n";
    ok($doc['since'] === $py['since'] && $doc['until'] === $py['until'], 'since/until equal nightly_pool.window()');
    ok($doc['messages_read'] === $py['messages_read'], "messages read equal ({$doc['messages_read']})");
    ok($doc['window_keys'] === $py['window_keys'], 'window keys equal (before the archive filter)');
    ok($doc['keys'] === $py['keys'], 'night keys equal');
    ok(array_column($doc['bundles'], 'media_ids', 'key') === array_column($py['bundles'], 'media_ids', 'key'), 'every bundle: key and media_ids equal');
    // types: phase3's tg_m_video follows old Telethon (round video notes are not .video); Telethon ≥1.3x treats them as
    // .video. Keys and media_ids do not depend on it (only "other" splits a bundle); any OTHER type difference fails.
    $known = [180 => ['document', 'video']]; $diff = []; $bad = 0;
    foreach ($doc['bundles'] as $i => $b) foreach ($b['types'] as $k => $t) {
        $pt = $py['bundles'][$i]['types'][$k] ?? null;
        if ($t === $pt) continue;
        $mid = $b['media_ids'][$k]; $diff[] = "$mid php=$t python=$pt";
        if (($known[$mid] ?? null) !== [$t, $pt]) $bad++;
    }
    if ($diff) echo "  KNOWN DIVERGENCE (types only, round video note): " . implode('; ', $diff) . "\n";
    ok($bad === 0, 'every bundle: types equal except the known round-video-note case');
    ok($doc['keys'] === ['b131', 'b150', 'b152', 'b170', 'b180', 'b182', 'b400', 'b501'], 'expected keys (hand-checked)');
    ok($doc['excluded_archived'] === $ARCHIVED, 'archived keys excluded (b190, b404)');
}
ok(!in_array(false, tg_grouping_selftest(), true), 'phase3 grouping self-tests (7) pass');

// ---- failure path: bounded retries --------------------------------------------------------------------
$D2 = '2026-11-11';
$GLOBALS['gh']['put_code'] = 500;
ok(tg_host_plan_tick($teh('2026-11-11 03:07')) === 'queued', 'next night queued');
$attempt = function () { $q = glob(tg_var('queue') . '/*.json'); $j = tg_read_json($q[0]);
    try { job_host_plan($j['args'], fn($p) => null, 'internal'); tg_status($j['id'], ['state' => 'done']); }
    catch (Throwable $e) { tg_status($j['id'], ['state' => 'failed', 'error' => $e->getMessage()]); } @unlink($q[0]); };
// the job stamps next_at with the wall clock; move it onto the test's fake clock
$fake = function (string $d, int $at) { $s = tg_host_plan_state(); $s[$d]['next_at'] = $at; tg_write_json(tg_var('host-plan.json'), $s); };
$attempt(); $fake($D2, $teh('2026-11-11 03:07') + 120);
ok((tg_host_plan_state()[$D2]['state'] ?? '') === 'failed', 'a failed repo write marks the night failed');
ok(tg_host_plan_tick($teh('2026-11-11 03:08')) === 'backoff', 'retry waits for the backoff');
$t = $teh('2026-11-11 03:12');
foreach ([2, 3, 4] as $n) { ok(tg_host_plan_tick($t) === 'queued' && tg_host_plan_state()[$D2]['attempts'] === $n, "retry $n queued (outside the slot too)"); $attempt(); $fake($D2, $t + 120); $t += 600; }
ok(tg_host_plan_tick($t) === 'gave-up' && tg_host_plan_state()[$D2]['state'] === 'gave_up', 'after 4 attempts ⇒ gave up, nothing queued');
ok(!glob(tg_var('queue') . '/*.json'), 'queue empty');

// killed beyond the tick's resume cap ⇒ attempt spent
$GLOBALS['gh']['put_code'] = 201;
ok(tg_host_plan_tick($teh('2026-11-12 03:07')) === 'queued', 'third night queued');
$q = glob(tg_var('queue') . '/*.json'); $j = tg_read_json($q[0]); @unlink($q[0]);
tg_status($j['id'], ['state' => 'failed', 'error' => 'killed by host']);
ok(tg_host_plan_tick($teh('2026-11-12 03:08')) === 'backoff' && tg_host_plan_state()['2026-11-12']['state'] === 'failed', 'killed job ⇒ night failed, retried after backoff');

// switch: off, live ⇒ dry, manual plan_date, unreadable
file_put_contents(tg_var('host-config.json'), '{"mode":"off"}');
ok(tg_host_plan_tick($teh('2026-11-13 03:07')) === 'off', 'mode off ⇒ nothing');
file_put_contents(tg_var('host-config.json'), '{oops');
ok(tg_host_config()['mode'] === 'off', 'unreadable host-config.json ⇒ off (fail closed)');
file_put_contents(tg_var('host-config.json'), '{"mode":"live","limit":1,"plan_date":"2026-11-05"}');
ok(tg_host_plan_tick($teh('2026-11-13 12:00')) === 'manual:queued', 'plan_date ⇒ that night queued once, any time of day');
$q = glob(tg_var('queue') . '/*.json'); $j = tg_read_json($q[0]);
ok($j['args']['date'] === '2026-11-05', 'manual job carries plan_date');
$GLOBALS['gh']['files'] = []; $r = job_host_plan($j['args'], fn($p) => null, 'internal'); tg_status($j['id'], ['state' => 'done']); @unlink($q[0]);
$d5 = json_decode($GLOBALS['gh']['files']['Memory-Workflow/nightly-pool/host/2026-11-05.json'], true);
ok($d5['mode'] === 'dry' && $d5['mode_requested'] === 'live' && $d5['limit'] === 1, 'live requested ⇒ runs dry, recorded');
ok(tg_host_plan_tick($teh('2026-11-13 12:01')) === 'not-due', 'manual night done ⇒ falls through to the nightly slot (not due at noon)');
file_put_contents(tg_var('host-config.json'), '{"mode":"dry","plan_date":"2026-12-30"}');
ok(tg_host_plan_tick($teh('2026-11-14 03:07')) === 'queued' && isset(tg_host_plan_state()['2026-11-14']), 'future plan_date ignored; the nightly slot still runs');
try { job_host_plan(['date' => $DATE], fn($p) => null, 'read'); ok(false, 'door role cannot run host_plan'); } catch (Throwable $e) { ok(true, 'door role cannot run host_plan'); }

tg_rm_r($tmp);
echo "\n# host plan tests: $F failures\n"; exit($F ? 1 : 0);
