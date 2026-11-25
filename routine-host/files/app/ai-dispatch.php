<?php
// Multi-AI dispatch adapters for routine.hohedoc.ir (app v68).
// Reads non-secret routing from control.hohedoc.ir/var/ (synced from Memory-Workflow/ai-routing/)
// and decrypts credentials on-host from control.hohedoc.ir/var/ai-vault/ (0600, never in git).
// Preserves all account-safety rules: r_armed(), r_country_ok(), and strict 429 cooldown (never rotate on 429).
declare(strict_types=1);

foreach ([
    dirname((string) realpath(__DIR__ . '/..')) . '/control.hohedoc.ir/app/ai-vault.php',
    __DIR__ . '/../../../control-host/files/app/ai-vault.php',
    __DIR__ . '/../../control-host/files/app/ai-vault.php',
] as $_ai_vp) {
    if (is_file($_ai_vp)) { require_once $_ai_vp; break; }
}

function rt_ai_control_var(): string {
    $sib = dirname((string) realpath(R_ROOT)) . '/control.hohedoc.ir/var';
    return is_dir($sib) ? $sib : (R_ROOT . '/var');
}

function rt_ai_is_rate_limited(int $httpCode): bool {
    return $httpCode === 429;
}

/** Pure resolver: given process_key, executors table, and catalog, return active account row or null. */
function rt_ai_resolve_process(string $processKey, array $executors, array $catalog): ?array {
    $row = $executors['processes'][$processKey] ?? null;
    if (!is_array($row)) return null;
    $alias = (string) ($row['account_alias'] ?? '');
    if ($alias === '') return null;
    foreach (($catalog['accounts'] ?? []) as $acct) {
        if (is_array($acct) && ($acct['alias'] ?? '') === $alias && !empty($acct['enabled'])) {
            return $acct;
        }
    }
    return null;
}

/** Read master key from sibling control.hohedoc.ir/secrets.php without leaking it. */
function rt_ai_master_key(): ?string {
    $ctlSecrets = dirname((string) realpath(R_ROOT)) . '/control.hohedoc.ir/secrets.php';
    if (is_file($ctlSecrets)) {
        require_once $ctlSecrets;
    }
    return ai_vault_master_key();
}

/** Find the existing legacy Claude routine key (acct-a) on routine.hohedoc.ir/keys/. */
function rt_ai_legacy_claude_key(?array $legacyKeys = null): ?array {
    $keys = $legacyKeys ?? (function_exists('r_keys') ? (r_keys()['keys'] ?? []) : []);
    $acctA = $keys['acct-a'] ?? null;
    if (!is_array($acctA) || empty($acctA)) return null;
    if (isset($acctA['agent-project-backup']) && is_array($acctA['agent-project-backup'])) {
        $k = $acctA['agent-project-backup'];
        if (!empty($k['url']) && !empty($k['token'])) return $k;
    }
    foreach ($acctA as $k) {
        if (is_array($k) && !empty($k['url']) && !empty($k['token'])) return $k;
    }
    return null;
}

/**
 * One-way on-host bridge: import existing routine-host Claude key (acct-a) into
 * control.hohedoc.ir/var/ai-vault/acct-claude-main.enc.json (AES-256-GCM, 0600)
 * and record ONLY non-secret hint metadata in ai-vault-hints.json (0600).
 * Never overwrites an existing vault entry (user edits from the app always win).
 */
function rt_ai_sync_legacy_claude_keys(?string $cvar = null, ?string $masterKey = null, ?array $legacyKeys = null): array {
    $cvar = $cvar ?? rt_ai_control_var();
    $mk = $masterKey ?? rt_ai_master_key();
    if ($mk === null || strlen($mk) !== 32) {
        return ['synced' => false, 'reason' => 'master key unavailable'];
    }
    $legacy = rt_ai_legacy_claude_key($legacyKeys);
    if ($legacy === null) {
        return ['synced' => false, 'reason' => 'no legacy acct-a key'];
    }

    $alias = 'acct-claude-main';
    $vdir = rtrim($cvar, '/') . '/ai-vault';
    $vfile = ai_vault_file($vdir, $alias);
    if ($vfile !== null && is_file($vfile)) {
        return ['synced' => false, 'reason' => 'already present in vault'];
    }

    $secret = [
        'token' => (string) $legacy['token'],
        'url' => (string) $legacy['url'],
        'beta' => (string) ($legacy['beta'] ?? (defined('R_DEFAULT_BETA') ? R_DEFAULT_BETA : '')),
        'header' => '',
    ];
    if (!ai_vault_put($vdir, $mk, $alias, $secret)) {
        return ['synced' => false, 'reason' => 'vault write failed'];
    }

    $hintsFile = rtrim($cvar, '/') . '/ai-vault-hints.json';
    $hints = @json_decode((string) @file_get_contents($hintsFile), true);
    if (!is_array($hints)) $hints = [];
    $hints[$alias] = [
        'secret_present' => true,
        'secret_hint' => ai_vault_hint($secret['token']),
        'source' => 'routine-host:acct-a',
        'synced_at' => time(),
    ];
    $tmp = $hintsFile . '.tmp';
    if (@file_put_contents($tmp, json_encode($hints, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false) {
        @chmod($tmp, 0600);
        @rename($tmp, $hintsFile);
    }

    return ['synced' => true, 'alias' => $alias, 'hint' => $hints[$alias]['secret_hint']];
}

/** Dispatch one job/stage through the selected AI account adapter. */
function rt_ai_fire_process(string $processKey, string $jobId, string $runId, array $extra = []): ?array {
    $cvar = rt_ai_control_var();
    $exec = @json_decode((string) @file_get_contents($cvar . '/ai-process-executors.json'), true) ?: [];
    $cat = @json_decode((string) @file_get_contents($cvar . '/ai-accounts-catalog.json'), true) ?: [];
    $acct = rt_ai_resolve_process($processKey, $exec, $cat);
    if ($acct === null) return null; // caller falls back to legacy jobs.json path

    $alias = (string) $acct['alias'];
    $mode = (string) ($acct['mode'] ?? '');
    $mk = rt_ai_master_key();
    $sec = ($mk !== null) ? ai_vault_get($cvar . '/ai-vault', $mk, $alias) : null;

    if ($mode === 'claude_routine') {
        if ((!is_array($sec) || empty($sec['url']) || empty($sec['token'])) && $alias === 'acct-claude-main') {
            $legacy = rt_ai_legacy_claude_key();
            if ($legacy !== null) {
                $sec = ['url' => (string) $legacy['url'], 'token' => (string) $legacy['token'], 'beta' => (string) ($legacy['beta'] ?? R_DEFAULT_BETA)];
            }
        }
        if (!is_array($sec) || empty($sec['url']) || empty($sec['token'])) {
            return ['state' => 'pack', 'why' => "کلیدِ {$alias} در گاوصندوقِ هاست ناقص است"];
        }
        $r = r_fire([
            'url' => (string) $sec['url'],
            'token' => (string) $sec['token'],
            'beta' => (string) ($sec['beta'] ?? R_DEFAULT_BETA),
        ], $jobId, $runId);
        $code = (int) $r['code'];
        if ($code >= 200 && $code < 300) {
            return ['state' => 'fired', 'via' => $alias, 'fired_at' => time(), 'session' => (string) ($r['session'] ?? '')];
        }
        if (rt_ai_is_rate_limited($code)) {
            return ['state' => 'pack', 'why' => "سقفِ مصرفِ {$alias} (429)", 'code' => 429, 'retry' => (int) ($r['retry'] ?? 3600)];
        }
        if ($code === 403) return ['state' => 'halt', 'why' => 'HTTP 403 — شاید منطقه یا سازمان بسته است'];
        return ['state' => 'pack', 'why' => "پاسخِ خطا از {$alias} (HTTP {$code})"];
    }

    if ($mode === 'webhook' || $mode === 'api_key') {
        if (!is_array($sec) || empty($sec['url'])) {
            return ['state' => 'pack', 'why' => "نشانیِ {$alias} در گاوصندوقِ هاست نیست"];
        }
        $hdrs = ['Content-Type: application/json', 'User-Agent: routine-hohedoc'];
        if (!empty($sec['token'])) $hdrs[] = 'Authorization: Bearer ' . $sec['token'];
        if (!empty($sec['header'])) $hdrs[] = (string) $sec['header'];
        $payload = json_encode(['process' => $processKey, 'job' => $jobId, 'run_id' => $runId, 'model' => (string) ($acct['model'] ?? '')] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ch = curl_init((string) $sec['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $hdrs, CURLOPT_POSTFIELDS => $payload]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) return ['state' => 'fired', 'via' => $alias, 'fired_at' => time(), 'session' => ''];
        if (rt_ai_is_rate_limited($code)) return ['state' => 'pack', 'why' => "سقفِ مصرفِ {$alias} (429)", 'code' => 429, 'retry' => 3600];
        return ['state' => 'pack', 'why' => "پاسخِ خطا از {$alias} (HTTP {$code})"];
    }

    if ($mode === 'github_queue') {
        // Queue-based employees (e.g. Grok/Gemini/Codex) pick up requests from GitHub; optional wake webhook if set.
        if (is_array($sec) && !empty($sec['url'])) {
            $ch = curl_init((string) $sec['url']);
            $hdrs = ['Content-Type: application/json'];
            if (!empty($sec['token'])) $hdrs[] = 'Authorization: Bearer ' . $sec['token'];
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true, CURLOPT_HTTPHEADER => $hdrs,
                CURLOPT_POSTFIELDS => json_encode(['process' => $processKey, 'job' => $jobId, 'run_id' => $runId] + $extra)]);
            curl_exec($ch);
            curl_close($ch);
        }
        return ['state' => 'fired', 'via' => $alias . ' (queue)', 'fired_at' => time(), 'session' => ''];
    }

    return ['state' => 'pack', 'why' => "روشِ ناشناخته برای {$alias}"];
}
