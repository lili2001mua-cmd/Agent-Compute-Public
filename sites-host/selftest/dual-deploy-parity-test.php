<?php
// Offline selftest for STY-05 Step 118 (declarative dual-target fan-out & cross-host SHA-256 hash parity).
require_once __DIR__ . '/../files/app/lib.php';
require_once __DIR__ . '/../files/app/ops.php';

$pass = 0; $fail = 0;
function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS: $label\n"; }
    else { $fail++; echo "FAIL: $label\n"; }
}

$sites = require __DIR__ . '/../files/app/sites.php';
$st = $sites['stylistcrm'];

// 1. Declarative mirror lookup from sites.php (zero hardcoded hostnames)
ok(dh_mirror_envs($st, 'pwa') === ['pwa-de'], 'pwa resolves declarative mirror pwa-de');
ok(dh_mirror_envs($st, 'hub') === ['hub-de'], 'hub resolves declarative mirror hub-de');
ok(dh_mirror_envs($st, 'pwa-de') === [], 'mirror env itself returns empty (no fan-out loop)');
ok(dh_mirror_envs($st, 'help') === [], 'env without mirror returns empty');

// 2. Portability: swapping mirror target in config requires zero code changes
$portableSite = $st;
$portableSite['envs']['pwa-ir2'] = 'https://pwa2.example.ir/deploy/sync.php';
$portableSite['mirrors']['pwa'] = ['pwa-ir2'];
ok(dh_mirror_envs($portableSite, 'pwa') === ['pwa-ir2'], 'switching mirror to new host works via config only');

// 3. Deployed file SHA-256 cross-host verification
$h1 = str_repeat('a', 64);
$h2 = str_repeat('b', 64);
$v1 = dh_verify_deploy_hashes(
    [['file' => 'pwa/public_html/index.php', 'ok' => true, 'sha256' => $h1]],
    [['file' => 'pwa/public_html/index.php', 'ok' => true, 'sha256' => $h1]]
);
ok($v1['all_match'] === true && $v1['checked'] === 1, 'identical deploy hashes match');

$v2 = dh_verify_deploy_hashes(
    [['file' => 'pwa/public_html/index.php', 'ok' => true, 'sha256' => $h1]],
    [['file' => 'pwa/public_html/index.php', 'ok' => true, 'sha256' => $h2]]
);
ok($v2['all_match'] === false && count($v2['mismatches']) === 1, '1-byte deploy hash drift detected');

// 4. Full manifest comparison ignores host-local secrets & detects real drift
$mPrimary = [
    'index.php' => $h1,
    'core/App.php' => $h2,
    'deploy/config.php' => str_repeat('1', 64),
    'config/database.local.php' => str_repeat('2', 64),
    'uploads/avatar.jpg' => str_repeat('3', 64),
];
$mMirror = [
    'index.php' => $h1,
    'core/App.php' => $h2,
    'deploy/config.php' => str_repeat('9', 64),
    'config/database.local.php' => str_repeat('8', 64),
];
$c1 = dh_compare_file_manifests($mPrimary, $mMirror);
ok($c1['all_match'] === true && $c1['matched'] === 2 && $c1['ignored'] === 3, 'manifest parity ignores secrets/uploads and matches app files');

$mMirrorDrift = $mMirror;
$mMirrorDrift['core/App.php'] = $h1;
$mMirrorDrift['extra.php'] = $h2;
$c2 = dh_compare_file_manifests($mPrimary, $mMirrorDrift);
ok($c2['all_match'] === false && $c2['counts']['hash_mismatch'] === 1 && $c2['counts']['only_in_mirror'] === 1, 'manifest parity detects hash mismatch and extra mirror file');

echo "\nTOTAL: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
