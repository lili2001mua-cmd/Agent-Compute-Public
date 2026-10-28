<?php
// Diagnostic capability probe (JSON API, no UI/layout/CSS, no secret value). Booleans/paths only.
// Purpose: verify whether this shared host can run exec/python (the "can Telethon run here?" question).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
$fns = ['exec', 'shell_exec', 'proc_open', 'popen', 'system', 'passthru'];
$fn_status = [];
foreach ($fns as $f) {
    $fn_status[$f] = function_exists($f) && !in_array($f, $disabled, true);
}

$python = null;
$python_version = null;
if ($fn_status['exec'] || $fn_status['shell_exec']) {
    foreach (['python3', 'python'] as $p) {
        $path = @shell_exec("command -v $p 2>/dev/null");
        if ($path && trim($path) !== '') {
            $python = trim($path);
            $python_version = trim((string) @shell_exec("$p --version 2>&1"));
            break;
        }
    }
}

echo json_encode([
    'php' => PHP_VERSION,
    'exec_family' => $fn_status,
    'disable_functions_count' => count($disabled),
    'python_path' => $python,
    'python_version' => $python_version,
    'has_curl' => function_exists('curl_init'),
    'ext_sockets' => extension_loaded('sockets'),
    'ext_openssl' => extension_loaded('openssl'),
    'ext_gmp' => extension_loaded('gmp'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
