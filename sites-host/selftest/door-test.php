<?php
// Selftest for sites-host/files/app/door.php against a local mock sync.php bridge.
// Runs in-process with DH_DOOR_KEY + DH_SITE_BASE (no network, no real site touched).
$doorApp = $argv[1] ?? '';
$mockUrl = $argv[2] ?? '';
if (!$doorApp || !$mockUrl) { fwrite(STDERR, "usage: door-test.php <app/door.php> <mock-base-url>\n"); exit(2); }

putenv('DH_DOOR_KEY=test-door-key-0123456789abcdef');
putenv('DH_SITE_BASE=' . $mockUrl);
require_once $doorApp;

$fail = 0;
$check = function (string $label, bool $ok) use (&$fail) {
    echo ($ok ? "PASS " : "FAIL ") . "door: $label\n";
    if (!$ok) $fail = 1;
};

$keys = door_keys();
$check('door_keys derives 32-byte enc/mac', is_array($keys) && strlen($keys['enc']) === 32 && strlen($keys['mac']) === 32);

// 1. GET readiness probe
[$code, $out] = door_handle('GET', '');
$check('GET probe returns ready + stylistcrm', $code === 200 && ($out['door'] ?? '') === 'ready' && ($out['key'] ?? false) === true && in_array('stylistcrm', $out['sites'] ?? [], true));

// Helper to send a sealed request and open the sealed response
$call = function (array $payload, ?string $kind = null, ?int $exp = null, ?string $id = null) use ($keys): array {
    $id = $id ?? bin2hex(random_bytes(16));
    $exp = $exp ?? (time() + 300);
    $env = door_seal($keys, $kind ?? DOOR_KIND_REQ, $id, json_encode($payload), $exp);
    [$code, $out] = door_handle('POST', json_encode($env));
    $plain = ($code < 400 || isset($out['ct'])) ? door_open($keys, $out, DOOR_KIND_RES) : null;
    return [$code, $plain !== null ? json_decode($plain, true) : $out, $env];
};

// 2. Forged / wrong-kind / expired / replayed requests are rejected
[$c1] = door_handle('POST', '{"v":1,"kind":"sites-door"}');
$check('unsealed POST refused (403)', $c1 === 403);

[$c2] = $call(['action' => 'ping', 'env' => 'pwa'], 'pass');
$check('wrong kind refused (403)', $c2 === 403);

[$c3] = $call(['action' => 'ping', 'env' => 'pwa'], DOOR_KIND_REQ, time() - 10);
$check('expired envelope refused (403)', $c3 === 403);

// 3. Valid ping + replay check
$pingId = bin2hex(random_bytes(16));
[$c4, $r4, $pingEnv] = $call(['site' => 'stylistcrm', 'env' => 'pwa', 'action' => 'ping', 'actor' => 'gemini'], null, null, $pingId);
$check('valid sealed ping succeeds', $c4 === 200 && ($r4['ok'] ?? false) === true);

[$cReplay] = door_handle('POST', json_encode($pingEnv));
$check('replayed envelope refused (403)', $cReplay === 403);

// 4. Write without approval_quote refused
$content = "hello-v1\n";
[$cNoApp, $rNoApp] = $call([
    'site' => 'stylistcrm', 'env' => 'pwa', 'action' => 'deploy', 'actor' => 'gemini',
    'files' => [['repo' => 'pwa/public_html/probe.txt', 'b64' => base64_encode($content), 'sha256' => hash('sha256', $content)]],
]);
$check('deploy without approval_quote rejected (400)', $cNoApp === 400 && ($rNoApp['ok'] ?? true) === false);

// 5. Write (deploy), Read (snapshot), Edit (deploy), Delete (delete), Manifest, Query, Run
[$cDep, $rDep] = $call([
    'site' => 'stylistcrm', 'env' => 'pwa', 'action' => 'deploy', 'actor' => 'gemini',
    'approval_quote' => 'تأیید انتقال به هاست',
    'files' => [['repo' => 'pwa/public_html/probe.txt', 'b64' => base64_encode($content), 'sha256' => hash('sha256', $content)]],
]);
$check('sealed deploy succeeds with sha256', $cDep === 200 && ($rDep['ok'] ?? false) === true);

[$cSnap, $rSnap] = $call([
    'site' => 'stylistcrm', 'env' => 'pwa', 'action' => 'snapshot', 'actor' => 'gemini',
    'files' => ['pwa/public_html/probe.txt'],
]);
$check('sealed snapshot succeeds', $cSnap === 200 && ($rSnap['ok'] ?? false) === true);

[$cDel, $rDel] = $call([
    'site' => 'stylistcrm', 'env' => 'pwa', 'action' => 'delete', 'actor' => 'gemini',
    'approval_quote' => 'تأیید انتقال به هاست',
    'files' => ['pwa/public_html/probe.txt'],
]);
$check('sealed delete succeeds', $cDel === 200 && ($rDel['ok'] ?? false) === true);

[$cMan, $rMan] = $call(['site' => 'stylistcrm', 'env' => 'pwa', 'action' => 'manifest', 'actor' => 'gemini', 'start' => 0, 'limit' => 2]);
$check('sealed manifest succeeds', $cMan === 200 && ($rMan['ok'] ?? false) === true && count($rMan['bridge']['lines'] ?? []) === 2);

// 6. Secret & deploy/ paths refused
[$cSec1] = $call(['site' => 'stylistcrm', 'env' => 'help', 'action' => 'snapshot', 'files' => ['help/public_html/.env']]);
[$cSec2] = $call(['site' => 'stylistcrm', 'env' => 'pwa', 'action' => 'snapshot', 'files' => ['pwa/public_html/config/database.php']]);
[$cSec3] = $call(['site' => 'stylistcrm', 'env' => 'pwa', 'action' => 'snapshot', 'files' => ['pwa/public_html/deploy/sync.php']]);
$check('secret and deploy/ paths refused (403)', $cSec1 === 403 && $cSec2 === 403 && $cSec3 === 403);

// 7. Rate limit closes door after 20 bad attempts
for ($i = 0; $i < 20; $i++) door_handle('POST', '{"bad":1}');
[$cRate] = door_handle('POST', '{"bad":1}');
$check('rate limit closes door (429)', $cRate === 429);

exit($fail);
