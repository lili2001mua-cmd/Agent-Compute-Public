<?php
// Selftest for sites-host/files/app/da_provision.php and door.php DA actions (STY-20).
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/da_prov_test_' . bin2hex(random_bytes(4));
$siteRoot = $tmp . '/domains/sites.hohedoc.ir';
$vaultDir = $tmp . '/holding-vault';
$domainsDir = $tmp . '/domains';
mkdir($siteRoot . '/var', 0700, true);
mkdir($vaultDir, 0700, true);
mkdir($domainsDir . '/pwa-de.hohedoc.ir/public_html', 0755, true);

file_put_contents($siteRoot . '/secrets.php', "<?php return ['STYLISTCRM_DEPLOY_SECRET' => 'test-deploy-secret-123456'];\n");
$fakeSync = $tmp . '/sync.php';
file_put_contents($fakeSync, "<?php echo json_encode(['ok' => true]);\n");

// Start a tiny local mock DirectAdmin HTTP server
$mockScript = $tmp . '/mock_da.php';
file_put_contents($mockScript, <<<'PHP'
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '';
$auth = $_SERVER['PHP_AUTH_USER'] ?? '';
$pw = $_SERVER['PHP_AUTH_PW'] ?? '';
if ($auth !== 'dauser' || $pw !== 'scoped-login-key-xyz') {
    http_response_code(401);
    echo 'error=1&text=Unauthorized';
    exit;
}
parse_str((string) file_get_contents('php://input'), $post);
if (strpos($uri, 'CMD_API_SUBDOMAINS') !== false && ($post['subdomain'] ?? '') === 'pwa-de') {
    echo 'error=0&text=Subdomain+Created&details=ok';
} elseif (strpos($uri, 'CMD_API_SSL') !== false && ($post['request'] ?? '') === 'letsencrypt') {
    echo 'error=0&text=SSL+Requested&details=ok';
} elseif (strpos($uri, 'CMD_API_DATABASES') !== false && ($post['name'] ?? '') === 'stylist_de') {
    echo 'error=0&text=Database+Created&details=ok';
} else {
    http_response_code(400);
    echo 'error=1&text=Unexpected+Request';
}
PHP);

$port = 18923;
$proc = proc_open(
    ['php', '-S', "127.0.0.1:$port", $mockScript],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
usleep(350000);

$ok = 0; $fail = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "PASS: $name\n"; }
    else { $fail++; echo "FAIL: $name" . ($detail ? " ($detail)" : '') . "\n"; }
}

try {
    define('DH_ROOT', $siteRoot);
    putenv("DH_ROOT=$siteRoot");
    putenv("DH_VAULT_DIR=$vaultDir");
    putenv("DH_DOMAINS_DIR=$domainsDir");
    putenv("DH_SYNC_PHP_OVERRIDE=$fakeSync");
    putenv("DH_DOOR_KEY=0123456789abcdef0123456789abcdef");

    require_once __DIR__ . '/../files/app/door.php';

    // 1. Vault missing initially
    da_provision_tick();
    $st0 = da_status_snapshot();
    check('vault_missing_before_config', ($st0['vault']['ready'] ?? true) === false);

    // 2. Write da-api.php into vault
    file_put_contents($vaultDir . '/da-api.php', "<?php return ['url' => 'http://127.0.0.1:$port', 'user' => 'dauser', 'key' => 'scoped-login-key-xyz', 'domain' => 'hohedoc.ir'];\n");
    chmod($vaultDir . '/da-api.php', 0600);
    da_provision_tick();
    $st1 = da_status_snapshot();
    check('vault_ready_after_config', ($st1['vault']['ready'] ?? false) === true && ($st1['vault']['domain'] ?? '') === 'hohedoc.ir');
    check('vault_status_never_leaks_key', strpos(json_encode($st1), 'scoped-login-key-xyz') === false);

    // 3. door_dispatch rejects da_job without approval_quote
    [$c, $r] = door_dispatch(['action' => 'da_job', 'op' => 'create_subdomain', 'params' => ['subdomain' => 'pwa-de']], str_repeat('a', 32));
    check('da_job_requires_approval', $c === 400 && ($r['ok'] ?? true) === false);

    // 4. Rejects bad subdomain
    [$c, $r] = door_dispatch(['action' => 'da_job', 'op' => 'create_subdomain', 'approval_quote' => 'ok', 'params' => ['subdomain' => '../bad']], str_repeat('b', 32));
    check('rejects_bad_subdomain', $c === 400);

    // 5. Queue and execute create_subdomain, request_ssl, create_db, bootstrap_stylist_env
    $ids = [
        'sub' => str_repeat('1', 32),
        'ssl' => str_repeat('2', 32),
        'db'  => str_repeat('3', 32),
        'bs'  => str_repeat('4', 32),
    ];
    [$c1, $r1] = door_dispatch(['action' => 'da_job', 'op' => 'create_subdomain', 'approval_quote' => 'تایید', 'params' => ['subdomain' => 'pwa-de']], $ids['sub']);
    [$c2, $r2] = door_dispatch(['action' => 'da_job', 'op' => 'request_ssl', 'approval_quote' => 'تایید', 'params' => ['subdomain' => 'pwa-de']], $ids['ssl']);
    [$c3, $r3] = door_dispatch(['action' => 'da_job', 'op' => 'create_db', 'approval_quote' => 'تایید', 'params' => ['suffix' => 'stylist_de']], $ids['db']);
    check('jobs_queued', $c1 === 200 && $c2 === 200 && $c3 === 200);

    da_provision_tick();
    [, $resSub] = da_read_result($ids['sub']);
    [, $resSsl] = da_read_result($ids['ssl']);
    [, $resDb]  = da_read_result($ids['db']);
    check('create_subdomain_ok', ($resSub['ok'] ?? false) === true && ($resSub['fqdn'] ?? '') === 'pwa-de.hohedoc.ir');
    check('request_ssl_ok', ($resSsl['ok'] ?? false) === true);
    check('create_db_ok_and_vault_saved', ($resDb['ok'] ?? false) === true && ($resDb['vault_saved'] ?? false) === true && is_file($vaultDir . '/db-stylist_de.php'));

    $dbVault = include $vaultDir . '/db-stylist_de.php';
    check('db_password_never_in_result', strpos(json_encode($resDb), (string) $dbVault['password']) === false);

    // 6. Bootstrap stylist env (writes deploy/sync.php, deploy/.secret, config/database.local.php)
    [$c4, $r4] = door_dispatch(['action' => 'da_job', 'op' => 'bootstrap_stylist_env', 'approval_quote' => 'تایید', 'params' => ['subdomain' => 'pwa-de', 'env_type' => 'pwa', 'db_suffix' => 'stylist_de']], $ids['bs']);
    da_provision_tick();
    [, $resBs] = da_read_result($ids['bs']);
    check('bootstrap_ok', $c4 === 200 && ($resBs['ok'] ?? false) === true && ($resBs['sync_ready'] ?? false) === true && ($resBs['db_config'] ?? false) === true);
    check('sync_and_db_local_written', is_file($domainsDir . '/pwa-de.hohedoc.ir/public_html/deploy/sync.php') && is_file($domainsDir . '/pwa-de.hohedoc.ir/public_html/config/database.local.php'));
    check('bootstrap_result_no_secrets', strpos(json_encode($resBs), 'test-deploy-secret-123456') === false && strpos(json_encode($resBs), (string) $dbVault['password']) === false);

    // 7. door_path_ok maps pwa-de -> pwa
    check('door_path_ok_pwa_de', door_path_ok('pwa-de', 'pwa/public_html/index.php') === true && door_path_ok('pwa-de', 'hub/public_html/index.php') === false);
} finally {
    if (is_resource($proc)) proc_terminate($proc);
    exec('rm -rf ' . escapeshellarg($tmp));
}

echo "\nTotal: $ok passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
