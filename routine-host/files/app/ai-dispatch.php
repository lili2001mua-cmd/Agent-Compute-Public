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
