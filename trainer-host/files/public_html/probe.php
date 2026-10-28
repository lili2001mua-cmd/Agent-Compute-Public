<?php
// Diagnostic capability probe (JSON API, no UI/layout/CSS, no secret value). Booleans/paths/limits only.
// v2: adds the facts the host-migration committee needs (bcmath, FFI, limits, python binaries, panel hints).
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
$fns = ['exec', 'shell_exec', 'proc_open', 'popen', 'system', 'passthru', 'putenv', 'ini_set', 'set_time_limit'];
$fn_status = [];
foreach ($fns as $f) {
    $fn_status[$f] = function_exists($f) && !in_array($f, $disabled, true);
}

// Python binaries: presence only (file_exists needs no exec).
$py_candidates = [
    '/usr/bin/python3', '/usr/bin/python', '/usr/local/bin/python3', '/bin/python3',
    '/opt/alt/python312/bin/python3', '/opt/alt/python311/bin/python3', '/opt/alt/python310/bin/python3',
    '/opt/alt/python39/bin/python3', '/opt/alt/python38/bin/python3',
];
$py_present = [];
foreach ($py_candidates as $p) {
    if (@file_exists($p)) $py_present[] = $p;
}
$alt_python_dirs = [];
foreach (@glob('/opt/alt/python*', GLOB_ONLYDIR) ?: [] as $d) $alt_python_dirs[] = basename($d);
$home = dirname(__DIR__, 2);
$venv_dirs = [];
foreach (@glob($home . '/virtualenv/*', GLOB_ONLYDIR) ?: [] as $d) $venv_dirs[] = basename($d);
$passenger_hint = @file_exists('/usr/share/passenger') || @file_exists('/opt/passenger') || isset($_SERVER['PASSENGER_APP_ENV']);
$cloudlinux = @file_exists('/etc/cloudlinux-release') || @file_exists('/usr/sbin/cagefs');
$cpanel = @file_exists('/usr/local/cpanel') || @is_dir($home . '/.cpanel');
$directadmin = @file_exists('/usr/local/directadmin');

$ext = [];
foreach (['gmp', 'bcmath', 'ffi', 'sockets', 'openssl', 'curl', 'mbstring', 'json', 'xml', 'dom', 'zlib', 'fileinfo', 'hash', 'pcntl', 'posix', 'iconv', 'intl'] as $e) {
    $ext[$e] = extension_loaded($e);
}

echo json_encode([
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'exec_family' => $fn_status,
    'disable_functions_count' => count($disabled),
    'limits' => [
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'max_input_time' => ini_get('max_input_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
        'ffi_enable' => ini_get('ffi.enable'),
        'open_basedir_set' => ini_get('open_basedir') !== '' && ini_get('open_basedir') !== false,
    ],
    'extensions' => $ext,
    'python_binaries_present' => $py_present,
    'opt_alt_python_dirs' => $alt_python_dirs,
    'home_virtualenv_dirs' => $venv_dirs,
    'passenger_hint' => $passenger_hint,
    'cloudlinux_hint' => $cloudlinux,
    'cpanel_hint' => $cpanel,
    'directadmin_hint' => $directadmin,
    'tmp_writable' => is_writable(sys_get_temp_dir()),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
