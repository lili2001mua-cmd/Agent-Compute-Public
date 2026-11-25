<?php
// DirectAdmin local provisioning bridge for sites.hohedoc.ir (STY-20).
// Credentials live ONLY in <home>/holding-vault/da-api.php (chmod 0600, outside every public_html).
// Web requests (door.php) NEVER read the vault; they only queue strictly allowlisted jobs into var/da-jobs/.
// CLI cron (tick.php, every minute) executes queued jobs against DirectAdmin API and local filesystem,
// storing any generated DB passwords ONLY in <home>/holding-vault/db-<suffix>.php (chmod 0600) —
// never returning any secret in job results, logs, or HTTP responses.

const DA_VALID_OPS = [
    'create_subdomain'      => 1,
    'request_ssl'           => 1,
    'create_db'             => 1,
    'bootstrap_stylist_env' => 1,
];

function da_vault_dir(): ?string {
    $override = trim((string) getenv('DH_VAULT_DIR'));   // selftest override only
    if ($override !== '' && is_dir($override)) return rtrim($override, '/');
    $root = realpath(DH_ROOT) ?: DH_ROOT;
    foreach ([dirname($root, 2), dirname($root)] as $base) {
        if (@is_dir($base . '/holding-vault')) return $base . '/holding-vault';
    }
    return null;
}

function da_domains_dir(): string {
    $override = trim((string) getenv('DH_DOMAINS_DIR')); // selftest override only
    if ($override !== '') return rtrim($override, '/');
    $root = realpath(DH_ROOT) ?: DH_ROOT;
    return dirname($root);   // /home/<user>/domains
}

function da_jobs_dir(): string {
    $d = dh_var('da-jobs');
    @mkdir($d, 0700, true);
    return $d;
}

function da_load_vault_cfg(): ?array {
    if (PHP_SAPI !== 'cli') return null;
    $vd = da_vault_dir();
    if ($vd === null) return null;
    $f = $vd . '/da-api.php';
    if (!is_file($f)) return null;
    $cfg = @include $f;
    if (!is_array($cfg)) return null;
    $url = trim((string) ($cfg['url'] ?? ''));
    $user = trim((string) ($cfg['user'] ?? ''));
    $key = trim((string) ($cfg['key'] ?? ''));
    $domain = trim((string) ($cfg['domain'] ?? 'hohedoc.ir'));
    if ($url === '' || $user === '' || $key === '' || $domain === '') return null;
    return ['url' => rtrim($url, '/'), 'user' => $user, 'key' => $key, 'domain' => $domain];
}

function da_sub_ok(string $sub): bool {
    return (bool) preg_match('/^[a-z0-9][a-z0-9-]{1,24}$/', $sub);
}

function da_db_suffix_ok(string $s): bool {
    return (bool) preg_match('/^[a-z0-9_]{2,20}$/', $s);
}

/** Web-safe status read (booleans + job ids only, never credentials). */
function da_status_snapshot(): array {
    $st = @json_decode((string) @file_get_contents(dh_var('da-vault-status.json')), true);
    $jobsDir = da_jobs_dir();
    $pending = [];
    $done = [];
    foreach (@scandir($jobsDir) ?: [] as $f) {
        if (str_ends_with($f, '.req.json')) $pending[] = substr($f, 0, -9);
        elseif (str_ends_with($f, '.res.json')) $done[] = substr($f, 0, -9);
    }
    sort($pending);
    sort($done);
    return [
        'ok'      => true,
        'vault'   => is_array($st) ? $st : ['ready' => false, 'reason' => 'waiting_first_tick'],
        'pending' => array_slice($pending, -20),
        'done'    => array_slice($done, -20),
    ];
}

/** Queue one validated DA provisioning job (called from sealed door.php after approval_quote check). */
function da_queue_job(array $req, string $id, string $actor): array {
    $op = (string) ($req['op'] ?? '');
    if (!isset(DA_VALID_OPS[$op])) {
        return [400, ['ok' => false, 'error' => 'da op not allowed']];
    }
    $params = is_array($req['params'] ?? null) ? $req['params'] : [];
    $clean = [];

    if ($op === 'create_subdomain' || $op === 'request_ssl') {
        $sub = strtolower(trim((string) ($params['subdomain'] ?? '')));
        if (!da_sub_ok($sub)) return [400, ['ok' => false, 'error' => 'invalid subdomain']];
        $clean['subdomain'] = $sub;
    } elseif ($op === 'create_db') {
        $suffix = strtolower(trim((string) ($params['suffix'] ?? '')));
        if (!da_db_suffix_ok($suffix)) return [400, ['ok' => false, 'error' => 'invalid db suffix']];
        $clean['suffix'] = $suffix;
    } elseif ($op === 'bootstrap_stylist_env') {
        $sub = strtolower(trim((string) ($params['subdomain'] ?? '')));
        $envType = (string) ($params['env_type'] ?? '');
        $dbSuffix = strtolower(trim((string) ($params['db_suffix'] ?? '')));
        if (!da_sub_ok($sub)) return [400, ['ok' => false, 'error' => 'invalid subdomain']];
        if ($envType !== 'pwa' && $envType !== 'hub') return [400, ['ok' => false, 'error' => 'env_type must be pwa or hub']];
        if (!da_db_suffix_ok($dbSuffix)) return [400, ['ok' => false, 'error' => 'invalid db_suffix']];
        $clean = ['subdomain' => $sub, 'env_type' => $envType, 'db_suffix' => $dbSuffix];
    }

    $job = [
        'id'     => $id,
        'op'     => $op,
        'params' => $clean,
        'actor'  => $actor,
        'at'     => gmdate('c'),
    ];
    $path = da_jobs_dir() . '/' . $id . '.req.json';
    file_put_contents($path, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($path, 0600);
    return [200, ['ok' => true, 'queued' => true, 'job_id' => $id, 'op' => $op]];
}

/** Read one completed DA job result (sanitized, zero secrets). */
function da_read_result(string $jobId): array {
    if (!preg_match('/^[0-9a-f]{32}$/', $jobId)) {
        return [400, ['ok' => false, 'error' => 'invalid job_id']];
    }
    $resPath = da_jobs_dir() . '/' . $jobId . '.res.json';
    $reqPath = da_jobs_dir() . '/' . $jobId . '.req.json';
    if (is_file($resPath)) {
        $data = @json_decode((string) @file_get_contents($resPath), true);
        return [200, is_array($data) ? $data : ['ok' => false, 'error' => 'corrupt result']];
    }
    if (is_file($reqPath)) {
        return [200, ['ok' => true, 'state' => 'pending', 'job_id' => $jobId]];
    }
    return [404, ['ok' => false, 'error' => 'job not found']];
}

/** Execute one HTTP POST to DirectAdmin API (CLI only). */
function da_api_call(array $cfg, string $cmd, array $fields): array {
    $baseUrl = preg_replace('/[^\x21-\x7E]/', '', (string) $cfg['url']);
    if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
        $baseUrl = 'https://' . ltrim($baseUrl, '/');
    }
    $url = rtrim($baseUrl, '/') . '/' . ltrim($cmd, '/');
    $bodyStr = http_build_query($fields);
    $isLocal = (bool) preg_match('#^https?://(127\.0\.0\.1|localhost)(:|/)#i', $url);

    $raw = false;
    $http = 0;
    $curlErr = '';
    $streamErr = '';

    $ch = function_exists('curl_init') ? @curl_init() : false;
    if ($ch !== false) {
        $setOk = @curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bodyStr,
            CURLOPT_USERPWD        => $cfg['user'] . ':' . $cfg['key'],
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => !$isLocal,
            CURLOPT_SSL_VERIFYHOST => $isLocal ? 0 : 2,
        ]);
        if ($setOk) {
            $raw = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
        } else {
            $curlErr = 'curl_setopt_array_failed';
        }
        curl_close($ch);
    } else {
        $curlErr = 'curl_init_failed';
    }

    if ($raw === false) {
        // Fallback to native PHP HTTP stream wrapper (never leaks credentials)
        $auth = base64_encode($cfg['user'] . ':' . $cfg['key']);
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAuthorization: Basic $auth\r\n",
                'content'       => $bodyStr,
                'timeout'       => 60,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $hdrs = $http_response_header ?? [];
        if (isset($hdrs[0]) && preg_match('/\s(\d{3})\s/', $hdrs[0], $m)) $http = (int) $m[1];
        if ($raw === false) {
            $le = error_get_last();
            $streamErr = substr((string) ($le['message'] ?? 'stream_failed'), 0, 160);
        }
    }

    if ($raw === false) {
        return [
            'ok'    => false,
            'http'  => $http,
            'error' => $curlErr ?: $streamErr ?: 'http_failed',
            'diag'  => [
                'scheme'     => (string) (parse_url($url, PHP_URL_SCHEME) ?: ''),
                'host'       => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
                'port'       => (int) (parse_url($url, PHP_URL_PORT) ?: 0),
                'curl_err'   => $curlErr,
                'stream_err' => $streamErr,
            ],
        ];
    }
    parse_str((string) $raw, $parsed);
    $daErr = (int) ($parsed['error'] ?? 0);
    $text = trim((string) ($parsed['text'] ?? ''));
    $details = trim((string) ($parsed['details'] ?? ''));
    $ok = ($http >= 200 && $http < 300 && $daErr === 0);
    return [
        'ok'      => $ok,
        'http'    => $http,
        'text'    => substr($text, 0, 200),
        'details' => substr($details, 0, 240),
    ];
}

/** CLI-only: run one queued DA job. */
function da_execute_job(array $job, array $cfg): array {
    $op = (string) ($job['op'] ?? '');
    $p = is_array($job['params'] ?? null) ? $job['params'] : [];
    $domain = $cfg['domain'];
    $user = $cfg['user'];

    if ($op === 'create_subdomain') {
        $sub = (string) $p['subdomain'];
        $r = da_api_call($cfg, 'CMD_API_SUBDOMAINS', [
            'action'    => 'create',
            'domain'    => $domain,
            'subdomain' => $sub,
        ]);
        return ['ok' => $r['ok'], 'op' => $op, 'fqdn' => $sub . '.' . $domain, 'api' => $r];
    }

    if ($op === 'request_ssl') {
        $sub = (string) $p['subdomain'];
        $fqdn = $sub . '.' . $domain;
        $r = da_api_call($cfg, 'CMD_API_SSL', [
            'action'      => 'save',
            'type'        => 'create',
            'request'     => 'letsencrypt',
            'domain'      => $domain,
            'name'        => $fqdn,
            'keysize'     => 'secp384r1',
            'encryption'  => 'sha256',
            'le_select0'  => $fqdn,
            'le_select1'  => 'www.' . $fqdn,
        ]);
        return ['ok' => $r['ok'], 'op' => $op, 'fqdn' => $fqdn, 'api' => $r];
    }

    if ($op === 'create_db') {
        $suffix = (string) $p['suffix'];
        $vd = da_vault_dir();
        if ($vd === null) return ['ok' => false, 'op' => $op, 'error' => 'vault_dir_missing'];
        $dbFile = $vd . '/db-' . $suffix . '.php';
        $dbName = $user . '_' . $suffix;
        if (is_file($dbFile)) {
            return ['ok' => true, 'op' => $op, 'db_name' => $dbName, 'already_in_vault' => true];
        }
        $pass = bin2hex(random_bytes(16)); // 32 hex chars, generated inside host CLI only
        $r = da_api_call($cfg, 'CMD_API_DATABASES', [
            'action'  => 'create',
            'name'    => $suffix,
            'user'    => $suffix,
            'passwd'  => $pass,
            'passwd2' => $pass,
        ]);
        if (!$r['ok']) {
            return ['ok' => false, 'op' => $op, 'db_name' => $dbName, 'api' => $r];
        }
        $rec = [
            'host'     => 'localhost',
            'database' => $dbName,
            'username' => $dbName,
            'password' => $pass,
            'created'  => gmdate('c'),
        ];
        $body = "<?php\n// Auto-generated by sites-host da_provision.php — chmod 0600, never commit\nreturn " . var_export($rec, true) . ";\n";
        file_put_contents($dbFile . '.tmp', $body, LOCK_EX);
        @chmod($dbFile . '.tmp', 0600);
        rename($dbFile . '.tmp', $dbFile);
        return ['ok' => true, 'op' => $op, 'db_name' => $dbName, 'vault_saved' => true];
    }

    if ($op === 'bootstrap_stylist_env') {
        $sub = (string) $p['subdomain'];
        $envType = (string) $p['env_type'];
        $dbSuffix = (string) $p['db_suffix'];
        $fqdn = $sub . '.' . $domain;

        // DirectAdmin subdomain docroot can be either domains/<domain>/public_html/<sub> OR domains/<sub.domain>/public_html
        $domBase = da_domains_dir();
        $candidates = [
            $domBase . '/' . $fqdn . '/public_html',
            $domBase . '/' . $domain . '/public_html/' . $sub,
        ];
        $docroot = null;
        foreach ($candidates as $cand) {
            if (is_dir($cand)) { $docroot = $cand; break; }
        }
        if ($docroot === null) {
            return ['ok' => false, 'op' => $op, 'fqdn' => $fqdn, 'error' => 'subdomain_docroot_not_found'];
        }

        // 1. Fetch deploy/sync.php from stylistcrm main (or override in selftest)
        $syncSrc = getenv('DH_SYNC_PHP_OVERRIDE');
        if (is_string($syncSrc) && $syncSrc !== '' && is_file($syncSrc)) {
            $syncRaw = (string) file_get_contents($syncSrc);
        } else {
            $repoPath = $envType . '/public_html/deploy/sync.php';
            [$code, $syncRaw] = dh_gh('stylistcrm', 'GET', '/contents/' . dh_enc_path($repoPath) . '?ref=main',
                null, 'application/vnd.github.raw');
            if ($code !== 200 || !is_string($syncRaw) || strpos($syncRaw, '<?php') === false) {
                return ['ok' => false, 'op' => $op, 'error' => 'failed_to_fetch_sync_php', 'http' => $code];
            }
        }

        // 2. Write deploy/sync.php and deploy/.secret (using existing STYLISTCRM_DEPLOY_SECRET)
        $deploySecret = trim((string) (dh_secrets()['STYLISTCRM_DEPLOY_SECRET'] ?? ''));
        if ($deploySecret === '') {
            return ['ok' => false, 'op' => $op, 'error' => 'STYLISTCRM_DEPLOY_SECRET_missing'];
        }
        $deployDir = $docroot . '/deploy';
        @mkdir($deployDir, 0755, true);
        file_put_contents($deployDir . '/sync.php', $syncRaw, LOCK_EX);
        @chmod($deployDir . '/sync.php', 0644);
        file_put_contents($deployDir . '/.secret', $deploySecret . "\n", LOCK_EX);
        @chmod($deployDir . '/.secret', 0600);
        file_put_contents($deployDir . '/.htaccess', "<Files \".secret\">\nRequire all denied\n</Files>\n", LOCK_EX);

        // 3. Write config/database.local.php directly from holding-vault/db-<suffix>.php (chmod 0600)
        $vd = da_vault_dir();
        $dbFile = $vd ? ($vd . '/db-' . $dbSuffix . '.php') : '';
        $dbWritten = false;
        if ($dbFile !== '' && is_file($dbFile)) {
            $dbCfg = @include $dbFile;
            if (is_array($dbCfg) && !empty($dbCfg['database']) && !empty($dbCfg['password'])) {
                $cfgDir = $docroot . '/config';
                @mkdir($cfgDir, 0755, true);
                $localPhp = "<?php\n// Auto-written by sites-host da_provision.php from holding-vault — chmod 0600\nreturn " . var_export([
                    'host'     => (string) ($dbCfg['host'] ?? 'localhost'),
                    'database' => (string) $dbCfg['database'],
                    'username' => (string) $dbCfg['username'],
                    'password' => (string) $dbCfg['password'],
                ], true) . ";\n";
                file_put_contents($cfgDir . '/database.local.php', $localPhp, LOCK_EX);
                @chmod($cfgDir . '/database.local.php', 0600);
                $dbWritten = true;
            }
        }

        return [
            'ok'         => true,
            'op'         => $op,
            'fqdn'       => $fqdn,
            'env_type'   => $envType,
            'docroot'    => basename(dirname($docroot)) . '/' . basename($docroot),
            'sync_ready' => true,
            'db_config'  => $dbWritten,
        ];
    }

    return ['ok' => false, 'op' => $op, 'error' => 'unknown_op'];
}

/** CLI-only: called from tick.php every minute. Updates vault status and executes queued jobs. */
function da_provision_tick(): void {
    if (PHP_SAPI !== 'cli') return;
    $cfg = da_load_vault_cfg();
    $status = [
        'ready'  => $cfg !== null,
        'domain' => $cfg ? $cfg['domain'] : null,
        'at'     => gmdate('c'),
    ];
    file_put_contents(dh_var('da-vault-status.json'), json_encode($status, JSON_UNESCAPED_SLASHES), LOCK_EX);

    $jobsDir = da_jobs_dir();
    foreach (@scandir($jobsDir) ?: [] as $f) {
        if (!str_ends_with($f, '.req.json')) continue;
        $reqPath = $jobsDir . '/' . $f;
        $jobId = substr($f, 0, -9);
        $resPath = $jobsDir . '/' . $jobId . '.res.json';
        $job = @json_decode((string) @file_get_contents($reqPath), true);
        if (!is_array($job)) { @unlink($reqPath); continue; }
        if ($cfg === null) {
            $res = ['ok' => false, 'job_id' => $jobId, 'error' => 'da_vault_not_configured', 'at' => gmdate('c')];
        } else {
            try {
                $res = da_execute_job($job, $cfg) + ['job_id' => $jobId, 'at' => gmdate('c')];
            } catch (Throwable $e) {
                $res = ['ok' => false, 'job_id' => $jobId, 'error' => substr($e->getMessage(), 0, 200), 'at' => gmdate('c')];
            }
        }
        file_put_contents($resPath, json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($resPath, 0600);
        @unlink($reqPath);
    }
}
