<?php
// Red-first tests for v68: AI accounts vault (AES-256-GCM, zero secrets/routines in git),
// control-host ai-routing ops, and routine-host ai-dispatch adapters.
declare(strict_types=1);

$ok = 0; $fail = 0;
function check(bool $cond, string $label): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "✓ $label\n"; }
    else { $fail++; echo "✗ $label\n"; }
}

$ctlApp = __DIR__ . '/../files/app';
$rtApp = __DIR__ . '/../../routine-host/files/app';

check(is_file($ctlApp . '/ai-vault.php'), 'control-host ai-vault.php exists');
check(is_file($ctlApp . '/ai-routing.php'), 'control-host ai-routing.php exists');
check(is_file($rtApp . '/ai-dispatch.php'), 'routine-host ai-dispatch.php exists');

if ($fail > 0) {
    echo "RED: missing implementation files ($fail failed)\n";
    exit(1);
}

require_once $ctlApp . '/ai-vault.php';
require_once $ctlApp . '/ai-routing.php';
require_once $rtApp . '/ai-dispatch.php';

$tmp = sys_get_temp_dir() . '/ai-v68-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$vaultDir = $tmp . '/ai-vault';
$masterKey = random_bytes(32);

// 1. Vault encrypts at rest (AES-256-GCM) and never writes plaintext secrets or routine URLs to disk
$secretPayload = [
    'token' => 'sk-ant-secret-9988776655',
    'url' => 'https://api.anthropic.com/v1/routines/rt_secret_123/fire',
    'beta' => 'experimental-cc-routine',
];
check(ai_vault_put($vaultDir, $masterKey, 'acct-claude-main', $secretPayload), 'ai_vault_put writes encrypted secret');
$encFile = $vaultDir . '/acct-claude-main.enc.json';
check(is_file($encFile), 'encrypted file exists on disk');
$rawOnDisk = (string) file_get_contents($encFile);
check(strpos($rawOnDisk, 'sk-ant-secret-9988776655') === false, 'raw token is NOT plaintext on disk');
check(strpos($rawOnDisk, 'rt_secret_123') === false, 'routine trigger URL is NOT plaintext on disk');
$dec = ai_vault_get($vaultDir, $masterKey, 'acct-claude-main');
check(is_array($dec) && ($dec['token'] ?? '') === 'sk-ant-secret-9988776655', 'ai_vault_get decrypts token');
check(($dec['url'] ?? '') === 'https://api.anthropic.com/v1/routines/rt_secret_123/fire', 'ai_vault_get decrypts routine URL');
check(ai_vault_hint($secretPayload['token']) === '…6655', 'ai_vault_hint shows only last 4 chars');

// 2. Sanitized catalog row NEVER contains secrets, tokens, or routine/webhook URLs (zero secrets & zero routines in git!)
$sanitized = ai_catalog_sanitize_row([
    'alias' => 'acct-claude-main',
    'name' => 'کلادِ اصلی',
    'team' => 'ترینینگ',
    'provider' => 'claude',
    'mode' => 'claude_routine',
    'model' => 'Claude Opus 4.6',
    'enabled' => true,
    'token' => 'sk-ant-secret-9988776655',
    'url' => 'https://api.anthropic.com/v1/routines/rt_secret_123/fire',
    'beta' => 'experimental-cc-routine',
    'secret_present' => true,
    'secret_hint' => '…6655',
]);
$sanJson = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check(strpos($sanJson, 'sk-ant-secret') === false, 'catalog row strips token');
check(strpos($sanJson, 'api.anthropic.com') === false, 'catalog row strips routine URL');
check(strpos($sanJson, 'rt_secret_123') === false, 'catalog row strips routine ID');
check(($sanitized['alias'] ?? '') === 'acct-claude-main' && ($sanitized['provider'] ?? '') === 'claude', 'catalog row keeps safe metadata');
check(($sanitized['secret_present'] ?? false) === true && ($sanitized['secret_hint'] ?? '') === '…6655', 'catalog row keeps safe hint');

// 3. Validation rejects unknown provider / mode / bad alias
check(ai_account_validate(['alias' => 'acct-ok', 'name' => 'تست', 'team' => 'عمومی', 'provider' => 'grok', 'mode' => 'github_queue']) === null, 'valid account passes validation');
check(ai_account_validate(['alias' => 'bad/alias', 'name' => 'تست', 'team' => 'عمومی', 'provider' => 'grok', 'mode' => 'github_queue']) !== null, 'bad alias rejected');
check(ai_account_validate(['alias' => 'acct-x', 'name' => 'تست', 'team' => 'عمومی', 'provider' => 'unknown', 'mode' => 'api_key']) !== null, 'unknown provider rejected');
check(ai_account_validate(['alias' => 'acct-x', 'name' => 'تست', 'team' => 'عمومی', 'provider' => 'claude', 'mode' => 'magic']) !== null, 'unknown mode rejected');

// 4. Process executor validation & deletion dependency guard
$procs = [
    'trainer.nightly' => ['label' => 'اجرای شبانه', 'account_alias' => 'acct-claude-main'],
    'trainer.step3_mapping' => ['label' => 'گامِ ۳', 'account_alias' => 'acct-grok-trainer'],
];
$deps = ai_account_dependents('acct-claude-main', $procs);
check($deps === ['trainer.nightly'], 'ai_account_dependents finds processes using account');
check(ai_account_dependents('acct-unused', $procs) === [], 'ai_account_dependents empty for unused account');

// 5. Vault delete removes encrypted file cleanly
check(ai_vault_delete($vaultDir, 'acct-claude-main') && !is_file($encFile), 'ai_vault_delete removes file');

// 6. Routine-host adapter resolution & 429 safety
$resolved = rt_ai_resolve_process('trainer.nightly', ['processes' => $procs], ['accounts' => [$sanitized]]);
check(is_array($resolved) && ($resolved['alias'] ?? '') === 'acct-claude-main', 'rt_ai_resolve_process resolves active account');
$disabled = $sanitized; $disabled['enabled'] = false;
check(rt_ai_resolve_process('trainer.nightly', ['processes' => $procs], ['accounts' => [$disabled]]) === null, 'rt_ai_resolve_process skips disabled account');
check(rt_ai_is_rate_limited(429) === true && rt_ai_is_rate_limited(500) === false, 'rt_ai_is_rate_limited flags 429 strictly');

// Cleanup
@rmdir($vaultDir); @rmdir($tmp);

echo "\nPASS: $ok / " . ($ok + $fail) . "\n";
exit($fail > 0 ? 1 : 0);
