<?php
// Encrypted at-rest vault for AI account credentials (tokens, API keys, routine trigger URLs, webhooks).
// Lives ONLY on the German host at control.hohedoc.ir/var/ai-vault/ (dir 0700, files 0600).
// NEVER stored in GitHub, NEVER printed in logs or API responses.
declare(strict_types=1);

const AI_VAULT_ALIAS_RE = '/^[a-z0-9][a-z0-9-]{1,40}$/';

function ai_vault_dir(): string {
    $d = function_exists('ctl_var') ? ctl_var('ai-vault') : (__DIR__ . '/../var/ai-vault');
    if (!is_dir($d)) { mkdir($d, 0700, true); @chmod($d, 0700); }
    return $d;
}

function ai_vault_master_key(): ?string {
    $seed = '';
    if (defined('AI_VAULT_KEY') && is_string(AI_VAULT_KEY) && AI_VAULT_KEY !== '') {
        $seed = AI_VAULT_KEY;
    } elseif (defined('DEVICE_KEYS') && is_array(DEVICE_KEYS) && !empty(DEVICE_KEYS)) {
        $first = reset(DEVICE_KEYS);
        if (is_string($first) && $first !== '') $seed = $first;
    }
    return $seed !== '' ? hash('sha256', 'mcp-ai-vault-v1:' . $seed, true) : null;
}

function ai_vault_hint(string $secret): string {
    $s = trim($secret);
    if ($s === '') return '';
    if (strlen($s) <= 4) return '…';
    return '…' . substr($s, -4);
}

function ai_vault_file(string $dir, string $alias): ?string {
    if (!preg_match(AI_VAULT_ALIAS_RE, $alias)) return null;
    return rtrim($dir, '/') . '/' . $alias . '.enc.json';
}

function ai_vault_put(string $dir, string $masterKey, string $alias, array $secretData): bool {
    $path = ai_vault_file($dir, $alias);
    if ($path === null || strlen($masterKey) !== 32) return false;
    if (!is_dir($dir)) { mkdir($dir, 0700, true); @chmod($dir, 0700); }
    $plain = json_encode($secretData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($plain)) return false;
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $masterKey, OPENSSL_RAW_DATA, $iv, $tag, $alias);
    if (!is_string($ct) || $tag === '') return false;
    $blob = json_encode([
        'v' => 1,
        'alg' => 'aes-256-gcm',
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'ct' => base64_encode($ct),
        'updated_at' => time(),
    ], JSON_UNESCAPED_SLASHES);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $blob, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return rename($tmp, $path);
}

function ai_vault_get(string $dir, string $masterKey, string $alias): ?array {
    $path = ai_vault_file($dir, $alias);
    if ($path === null || !is_file($path) || strlen($masterKey) !== 32) return null;
    $env = json_decode((string) file_get_contents($path), true);
    if (!is_array($env) || empty($env['iv']) || empty($env['tag']) || empty($env['ct'])) return null;
    $iv = base64_decode((string) $env['iv'], true);
    $tag = base64_decode((string) $env['tag'], true);
    $ct = base64_decode((string) $env['ct'], true);
    if (!is_string($iv) || !is_string($tag) || !is_string($ct)) return null;
    $plain = openssl_decrypt($ct, 'aes-256-gcm', $masterKey, OPENSSL_RAW_DATA, $iv, $tag, $alias);
    if (!is_string($plain)) return null;
    $data = json_decode($plain, true);
    return is_array($data) ? $data : null;
}

function ai_vault_delete(string $dir, string $alias): bool {
    $path = ai_vault_file($dir, $alias);
    if ($path === null) return false;
    return !is_file($path) || @unlink($path);
}
