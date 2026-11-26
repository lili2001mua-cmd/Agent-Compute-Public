<?php
declare(strict_types=1);

define('CTL_ROOT', sys_get_temp_dir() . '/gs_test_ctl_' . getmypid());
@mkdir(CTL_ROOT, 0700, true);
require_once __DIR__ . '/../files/app/germany_secrets.php';

$pass = 0;
function ok(bool $cond, string $msg): void {
    global $pass;
    if (!$cond) { fwrite(STDERR, "FAIL: {$msg}\n"); exit(1); }
    $pass++;
}

$tmp = sys_get_temp_dir() . '/gs_domains_' . getmypid();
$home = $tmp . '/home';
$domains = $home . '/domains';
@mkdir($domains . '/control.hohedoc.ir/var/ai-vault', 0700, true);
@mkdir($domains . '/sites.hohedoc.ir/public_html', 0700, true);
@mkdir($domains . '/ops.hohedoc.ir/var/vault-capsule', 0700, true);
@mkdir($home . '/holding-vault', 0700, true);

file_put_contents($domains . '/control.hohedoc.ir/secrets.php', "<?php\nconst DEVICE_KEYS = ['p1' => 'SUPER_SECRET_VALUE'];\ndefine('CTL_ADMIN_KEY', 'DO_NOT_LEAK');\n");
file_put_contents($domains . '/control.hohedoc.ir/var/ai-vault/grok.enc', 'ciphertext');
file_put_contents($domains . '/sites.hohedoc.ir/secrets.php', "<?php\nreturn ['STYLISTCRM_DEPLOY_SECRET' => 'NEVER_SHOW'];\n");
file_put_contents($home . '/holding-vault/gh-token.txt', 'tok');
file_put_contents($domains . '/ops.hohedoc.ir/var/vault-capsule/manifest.json', json_encode(['capsule_ready' => true, 'capsule_bytes' => 4096, 'capsule_sha256' => str_repeat('a', 64), 'files_count' => 4]));

$res = gs_scan_germany($domains, $home);
$json = json_encode($res);

ok($res['ok'] === true, 'scan ok');
ok($res['all_only_in_germany'] === true, 'compliant when all secrets in Germany');
ok($res['subdomains_count'] === 3, 'found holding-vault + control + sites');
ok(!str_contains($json, 'SUPER_SECRET_VALUE') && !str_contains($json, 'DO_NOT_LEAK') && !str_contains($json, 'NEVER_SHOW'), 'zero secret values leaked');
ok($res['capsule']['ready'] === true && $res['capsule']['bytes'] === 4096, 'capsule status parsed');

// Non-compliant case: secret placed in public_html or outside Germany
file_put_contents($domains . '/sites.hohedoc.ir/public_html/secrets.php', '<?php');
@mkdir($domains . '/other.example.com', 0700, true);
file_put_contents($domains . '/other.example.com/secrets.php', '<?php');
$bad = gs_scan_germany($domains, $home);
ok($bad['all_only_in_germany'] === false, 'detects secrets outside Germany / in public_html');
ok(count($bad['alerts']) === 2, 'reports both compliance alerts');

echo "PASS: {$pass} assertions\n";
