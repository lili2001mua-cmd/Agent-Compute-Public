<?php
// AI accounts catalog & generic process-executors management for control.hohedoc.ir (app v68).
// STRICT SECURITY INVARIANT:
//   - ZERO secrets, ZERO API keys, ZERO bearer tokens, and ZERO routine/webhook URLs are ever written to GitHub.
//   - GitHub (Memory-Workflow/ai-routing/accounts-catalog.json & process-executors.json) stores ONLY safe display metadata.
//   - All secrets and routine trigger URLs stay encrypted on-host in control.hohedoc.ir/var/ai-vault/ (chmod 0600).
declare(strict_types=1);

require_once __DIR__ . '/ai-vault.php';

const AI_CATALOG_PATH = 'Memory-Workflow/ai-routing/accounts-catalog.json';
const AI_EXECUTORS_PATH = 'Memory-Workflow/ai-routing/process-executors.json';
const AI_PROVIDERS = ['claude', 'grok', 'gemini', 'chatgpt', 'codex', 'cursor'];
const AI_MODES = ['claude_routine', 'webhook', 'api_key', 'github_queue'];
const AI_PROCESS_KEY_RE = '/^[a-z0-9][a-z0-9._-]{1,60}$/';

function ai_catalog_sanitize_row(array $in): array {
    $lt = is_array($in['last_test'] ?? null) ? [
        'at' => (int) ($in['last_test']['at'] ?? 0),
        'ok' => (bool) ($in['last_test']['ok'] ?? false),
        'note' => mb_substr(trim((string) ($in['last_test']['note'] ?? '')), 0, 120),
    ] : null;
    $row = [
        'alias' => strtolower(trim((string) ($in['alias'] ?? ''))),
        'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 80),
        'team' => mb_substr(trim((string) ($in['team'] ?? 'عمومی')), 0, 60),
        'provider' => strtolower(trim((string) ($in['provider'] ?? ''))),
        'mode' => strtolower(trim((string) ($in['mode'] ?? ''))),
        'model' => mb_substr(trim((string) ($in['model'] ?? '')), 0, 80),
        'enabled' => (bool) ($in['enabled'] ?? true),
        'secret_present' => (bool) ($in['secret_present'] ?? false),
        'secret_hint' => mb_substr(trim((string) ($in['secret_hint'] ?? '')), 0, 12),
    ];
    if ($lt !== null && $lt['at'] > 0) $row['last_test'] = $lt;
    return $row;
}

function ai_account_validate(array $in): ?string {
    $alias = strtolower(trim((string) ($in['alias'] ?? '')));
    if (!preg_match(AI_VAULT_ALIAS_RE, $alias)) return 'bad alias (lowercase letters, digits, hyphen)';
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) return 'bad name';
    $provider = strtolower(trim((string) ($in['provider'] ?? '')));
    if (!in_array($provider, AI_PROVIDERS, true)) return 'unknown provider';
    $mode = strtolower(trim((string) ($in['mode'] ?? '')));
    if (!in_array($mode, AI_MODES, true)) return 'unknown mode';
    $url = trim((string) ($in['url'] ?? ''));
    if ($url !== '' && !preg_match('#^https://[A-Za-z0-9._\-]+(?::\d+)?(?:/[^\s]*)?$#', $url)) {
        return 'url must be https';
    }
    if ($mode === 'claude_routine' && $url !== '' && !preg_match('#^https://api\.anthropic\.com/#', $url)) {
        return 'claude_routine url must point to https://api.anthropic.com/';
    }
    return null;
}

function ai_account_dependents(string $alias, array $processes): array {
    $out = [];
    foreach ($processes as $k => $row) {
        if (is_array($row) && (string) ($row['account_alias'] ?? '') === $alias) {
            $out[] = (string) $k;
        }
    }
    sort($out);
    return $out;
}

function ai_load_catalog(): array {
    $raw = function_exists('gh_file') ? gh_file(AI_CATALOG_PATH) : null;
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        $c = function_exists('ctl_json_read') ? ctl_json_read('ai-accounts-catalog.json', null) : null;
        $j = is_array($c) ? $c : ['version' => 1, 'updated_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'accounts' => []];
    }
    $clean = [];
    foreach (($j['accounts'] ?? []) as $row) {
        if (is_array($row) && !empty($row['alias'])) $clean[] = ai_catalog_sanitize_row($row);
    }
    $j['accounts'] = $clean;
    if (function_exists('ctl_json_write')) ctl_json_write('ai-accounts-catalog.json', $j);
    return $j;
}

function ai_load_executors(): array {
    $raw = function_exists('gh_file') ? gh_file(AI_EXECUTORS_PATH) : null;
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        $c = function_exists('ctl_json_read') ? ctl_json_read('ai-process-executors.json', null) : null;
        $j = is_array($c) ? $c : ['version' => 1, 'updated_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'processes' => []];
    }
    if (!is_array($j['processes'] ?? null)) $j['processes'] = [];
    if (function_exists('ctl_json_write')) ctl_json_write('ai-process-executors.json', $j);
    return $j;
}

function ai_handle_account_save(array $in): array {
    $err = ai_account_validate($in);
    if ($err !== null) return ['code' => 400, 'body' => ['ok' => false, 'error' => $err]];
    $mk = ai_vault_master_key();
    if ($mk === null) return ['code' => 503, 'body' => ['ok' => false, 'error' => 'vault master key unavailable']];

    $alias = strtolower(trim((string) $in['alias']));
    $vdir = ai_vault_dir();
    $prevSecret = ai_vault_get($vdir, $mk, $alias) ?: [];

    $tokenIn = trim((string) ($in['token'] ?? ''));
    $urlIn = trim((string) ($in['url'] ?? ''));
    $betaIn = trim((string) ($in['beta'] ?? ''));
    $headerIn = trim((string) ($in['header'] ?? ''));

    $newSecret = [
        'token' => $tokenIn !== '' ? $tokenIn : (string) ($prevSecret['token'] ?? ''),
        'url' => $urlIn !== '' ? $urlIn : (string) ($prevSecret['url'] ?? ''),
        'beta' => $betaIn !== '' ? $betaIn : (string) ($prevSecret['beta'] ?? ''),
        'header' => $headerIn !== '' ? $headerIn : (string) ($prevSecret['header'] ?? ''),
    ];
    $hasSecret = $newSecret['token'] !== '' || $newSecret['url'] !== '';
    if ($hasSecret) {
        if (!ai_vault_put($vdir, $mk, $alias, $newSecret)) {
            return ['code' => 500, 'body' => ['ok' => false, 'error' => 'failed to write encrypted vault']];
        }
    }

    $cat = ai_load_catalog();
    $existingTest = null;
    $accounts = [];
    foreach (($cat['accounts'] ?? []) as $row) {
        if (($row['alias'] ?? '') === $alias) { $existingTest = $row['last_test'] ?? null; continue; }
        $accounts[] = $row;
    }
    $sanitized = ai_catalog_sanitize_row($in + [
        'secret_present' => $hasSecret,
        'secret_hint' => ai_vault_hint($newSecret['token'] !== '' ? $newSecret['token'] : $newSecret['url']),
        'last_test' => $existingTest,
    ]);
    $accounts[] = $sanitized;
    usort($accounts, fn($a, $b) => strcmp($a['alias'], $b['alias']));
    $cat['version'] = 1;
    $cat['updated_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $cat['accounts'] = $accounts;

    require_once __DIR__ . '/toggle.php';
    [$ok, $sha] = ctl_commit(
        [AI_CATALOG_PATH => json_encode($cat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"],
        'ai-routing: ذخیرهٔ فرادادهٔ اکانتِ ' . $alias . ' از اپ (بی راز) [skip ci]'
    );
    if (!$ok) return ['code' => 502, 'body' => ['ok' => false, 'error' => 'commit failed: ' . $sha]];
    ctl_json_write('ai-accounts-catalog.json', $cat);
    ctl_log('ai-account-save ' . $alias . ' provider=' . $sanitized['provider'] . ' mode=' . $sanitized['mode']);
    return ['code' => 200, 'body' => ['ok' => true, 'account' => $sanitized, 'accounts' => $accounts]];
}

function ai_handle_account_delete(array $in): array {
    $alias = strtolower(trim((string) ($in['alias'] ?? '')));
    if (!preg_match(AI_VAULT_ALIAS_RE, $alias)) return ['code' => 400, 'body' => ['ok' => false, 'error' => 'bad alias']];
    $force = !empty($in['force']);
    $exec = ai_load_executors();
    $deps = ai_account_dependents($alias, $exec['processes'] ?? []);
    if ($deps && !$force) {
        return ['code' => 409, 'body' => ['ok' => false, 'error' => 'account in use', 'dependents' => $deps]];
    }
    ai_vault_delete(ai_vault_dir(), $alias);

    $cat = ai_load_catalog();
    $accounts = array_values(array_filter($cat['accounts'] ?? [], fn($r) => ($r['alias'] ?? '') !== $alias));
    $cat['updated_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $cat['accounts'] = $accounts;

    $files = [AI_CATALOG_PATH => json_encode($cat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"];
    if ($deps) {
        foreach ($deps as $pk) $exec['processes'][$pk]['account_alias'] = '';
        $exec['updated_utc'] = gmdate('Y-m-d\TH:i:s\Z');
        $files[AI_EXECUTORS_PATH] = json_encode($exec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    require_once __DIR__ . '/toggle.php';
    [$ok, $sha] = ctl_commit($files, 'ai-routing: حذفِ اکانتِ ' . $alias . ' از اپ [skip ci]');
    if (!$ok) return ['code' => 502, 'body' => ['ok' => false, 'error' => 'commit failed: ' . $sha]];
    ctl_json_write('ai-accounts-catalog.json', $cat);
    if ($deps) ctl_json_write('ai-process-executors.json', $exec);
    ctl_log('ai-account-delete ' . $alias);
    return ['code' => 200, 'body' => ['ok' => true, 'accounts' => $accounts]];
}

function ai_handle_account_test(array $in): array {
    $alias = strtolower(trim((string) ($in['alias'] ?? '')));
    if (!preg_match(AI_VAULT_ALIAS_RE, $alias)) return ['code' => 400, 'body' => ['ok' => false, 'error' => 'bad alias']];
    $cat = ai_load_catalog();
    $acct = null;
    foreach (($cat['accounts'] ?? []) as $row) if (($row['alias'] ?? '') === $alias) $acct = $row;
    if (!$acct) return ['code' => 404, 'body' => ['ok' => false, 'error' => 'unknown account']];

    $mk = ai_vault_master_key();
    $sec = ($mk !== null) ? ai_vault_get(ai_vault_dir(), $mk, $alias) : null;
    $mode = (string) $acct['mode'];
    $ok = false; $note = '';

    if ($mode === 'github_queue') {
        $ok = ctl_gh_token() !== null;
        $note = $ok ? 'صفِ گیت‌هاب آماده است' : 'توکنِ گیت‌هاب روی هاست نیست';
    } elseif (!is_array($sec) || (empty($sec['token']) && empty($sec['url']))) {
        $ok = false;
        $note = 'کلید یا نشانی در گاوصندوقِ هاست ثبت نشده';
    } elseif ($mode === 'claude_routine') {
        // Non-destructive check: validate URL shape & bearer presence without firing a live routine run.
        $u = (string) ($sec['url'] ?? '');
        $t = (string) ($sec['token'] ?? '');
        $ok = preg_match('#^https://api\.anthropic\.com/#', $u) === 1 && strlen($t) >= 20;
        $note = $ok ? 'ساختارِ کلید و نشانیِ روتین معتبر است (بی شلیکِ اضافی)' : 'نشانی یا توکنِ روتین ناقص است';
    } else {
        // webhook or api_key: lightweight HEAD/GET connectivity check to the endpoint host (never leaks token)
        $u = (string) ($sec['url'] ?? '');
        if ($u === '') {
            $ok = strlen((string) ($sec['token'] ?? '')) >= 12;
            $note = $ok ? 'کلید در گاوصندوقِ هاست ثبت شده است' : 'کلید خیلی کوتاه است';
        } else {
            $ch = curl_init($u);
            $hdrs = ['User-Agent: control-hohedoc-test'];
            if (!empty($sec['token'])) $hdrs[] = 'Authorization: Bearer ' . $sec['token'];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
                CURLOPT_NOBODY => ($mode === 'webhook'), CURLOPT_HTTPHEADER => $hdrs,
            ]);
            curl_exec($ch);
            $hc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($hc === 429) { $ok = false; $note = 'محدودیتِ مصرف (429) — دور زده نشد'; }
            elseif ($hc >= 200 && $hc < 500 && $hc !== 401 && $hc !== 403) { $ok = true; $note = 'پاسخِ زنده از سرور (HTTP ' . $hc . ')'; }
            else { $ok = false; $note = $hc > 0 ? ('خطای پاسخ: HTTP ' . $hc) : 'سرورِ مقصد پاسخ نداد'; }
        }
    }

    $testRec = ['at' => time(), 'ok' => $ok, 'note' => $note];
    foreach ($cat['accounts'] as $i => $row) {
        if (($row['alias'] ?? '') === $alias) $cat['accounts'][$i]['last_test'] = $testRec;
    }
    ctl_json_write('ai-accounts-catalog.json', $cat);
    ctl_log('ai-account-test ' . $alias . ' ok=' . ($ok ? '1' : '0'));
    return ['code' => 200, 'body' => ['ok' => true, 'test' => $testRec, 'accounts' => $cat['accounts']]];
}

function ai_handle_executors_set(array $in): array {
    $pk = trim((string) ($in['process_key'] ?? ''));
    $alias = strtolower(trim((string) ($in['account_alias'] ?? '')));
    if (!preg_match(AI_PROCESS_KEY_RE, $pk)) return ['code' => 400, 'body' => ['ok' => false, 'error' => 'bad process_key']];
    $cat = ai_load_catalog();
    if ($alias !== '') {
        $found = null;
        foreach (($cat['accounts'] ?? []) as $row) if (($row['alias'] ?? '') === $alias) $found = $row;
        if (!$found) return ['code' => 404, 'body' => ['ok' => false, 'error' => 'unknown account']];
        if (empty($found['enabled'])) return ['code' => 409, 'body' => ['ok' => false, 'error' => 'account is disabled']];
    }
    $exec = ai_load_executors();
    $label = trim((string) ($in['label'] ?? ($exec['processes'][$pk]['label'] ?? $pk)));
    $exec['version'] = 1;
    $exec['updated_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $exec['processes'][$pk] = [
        'label' => mb_substr($label, 0, 80),
        'account_alias' => $alias,
        'updated_at' => time(),
    ];
    require_once __DIR__ . '/toggle.php';
    [$ok, $sha] = ctl_commit(
        [AI_EXECUTORS_PATH => json_encode($exec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"],
        'ai-routing: انتخابِ مجریِ ' . $pk . ' -> ' . ($alias ?: 'none') . ' از اپ [skip ci]'
    );
    if (!$ok) return ['code' => 502, 'body' => ['ok' => false, 'error' => 'commit failed: ' . $sha]];
    ctl_json_write('ai-process-executors.json', $exec);
    ctl_log('process-executors-set ' . $pk . '=' . $alias);
    return ['code' => 200, 'body' => ['ok' => true, 'executors' => $exec]];
}
