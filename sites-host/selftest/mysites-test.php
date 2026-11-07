<?php
// Selftest of app/mysites.php: what this host encrypts, the studio host's key opens (byte-exact), a tampered file
// is refused, and pruning keeps the newest file plus at most 7 daily + 4 weekly + 12 monthly.
[$_, $mys, $studio, $tmp] = $argv;
require $mys;
eval('?>' . str_replace("require_once __DIR__ . '/lib.php';", "function studio_var(\$n){return sys_get_temp_dir().'/'.\$n;}", file_get_contents($studio)));
$r = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($r, $sk); $pk = openssl_pkey_get_details($r)['key'];
foreach ([0, 7, MYS_CHUNK, 2 * MYS_CHUNK + 7] as $n) {
    file_put_contents("$tmp/p", $n ? random_bytes($n) : '');
    mys_encrypt("$tmp/p", "$tmp/e", $pk);
    [, $h] = mys_decrypt(fopen("$tmp/e", 'rb'), null, ['sk' => $sk]);
    if ($h !== hash_file('sha256', "$tmp/p")) { fwrite(STDERR, "roundtrip $n\n"); exit(1); }
    $b = file_get_contents("$tmp/e"); $b[strlen($b) - 1] = chr(ord($b[strlen($b) - 1]) ^ 1); file_put_contents("$tmp/e", $b);
    try { mys_decrypt(fopen("$tmp/e", 'rb'), null, ['sk' => $sk]); fwrite(STDERR, "tamper $n\n"); exit(1); } catch (Throwable $e) {}
}
$f = [];
for ($d = 0; $d < 400; $d++) $f[] = ['id' => "x$d", 'appProperties' => ['taken_at' => gmdate('Y-m-d\TH:i:s\Z', 1790000000 - $d * 86400)]];
$k = mys_keep($f);
if (!isset($k['x0']) || count($k) < 12 || count($k) > 23 || isset($k['x399'])) { fwrite(STDERR, 'keep ' . count($k) . "\n"); exit(1); }
echo "ok\n";
