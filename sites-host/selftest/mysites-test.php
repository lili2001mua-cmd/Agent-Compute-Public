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
// STY-02 (گام ۱۰۷): آزمونِ بستهٔ افزایشی (NDJSON + gzip + MYS2 encrypt + studio mys_decrypt)
$incRows = [
    ['seq' => 10, 'tx_id' => 't1', 'table_name' => 'factor', 'pk_val' => '1', 'op' => 'I', 'company_id' => 6, 'before_json' => null, 'after_json' => '{"id":1}', 'created_at' => '2026-11-21 10:00:00.000000'],
    ['seq' => 11, 'tx_id' => 't2', 'table_name' => 'factor', 'pk_val' => '1', 'op' => 'U', 'company_id' => 6, 'before_json' => '{"id":1}', 'after_json' => '{"id":1,"status":2}', 'created_at' => '2026-11-21 10:01:00.000000'],
];
$built = mys_inc_build_ndjson('stylistcrm', 'pwa', $incRows, 'GENESIS', '2026-11-21T10:05:00Z');
if ($built['seq_from'] !== 10 || $built['seq_to'] !== 11 || $built['row_count'] !== 2) { fwrite(STDERR, "inc build bounds\n"); exit(1); }
file_put_contents("$tmp/inc.gz", gzencode($built['ndjson'], 6));
mys_encrypt("$tmp/inc.gz", "$tmp/inc.mys", $pk);
$decOut = fopen("$tmp/inc.dec.gz", 'wb');
[, $decHash] = mys_decrypt(fopen("$tmp/inc.mys", 'rb'), $decOut, ['sk' => $sk]);
fclose($decOut);
if ($decHash !== hash_file('sha256', "$tmp/inc.gz")) { fwrite(STDERR, "inc decrypt hash\n"); exit(1); }
$lines = array_values(array_filter(explode("\n", (string) gzdecode((string) file_get_contents("$tmp/inc.dec.gz")))));
$meta = json_decode($lines[0], true);
if (count($lines) !== 3 || ($meta['_meta'] ?? '') !== 'sty02-inc/v1' || ($meta['prev_sha256'] ?? '') !== 'GENESIS') { fwrite(STDERR, "inc meta\n"); exit(1); }
// STY-02 (گام ۱۰۹): آزمونِ زمان‌بندیِ هفتگیِ بکاپِ کامل + بازگشتِ خودکار به روزانه در صورتِ افتادنِ افزایشی
$satTeh = strtotime('2026-11-21 04:00:00 UTC'); // شنبه (w=6)
$friTeh = strtotime('2026-11-27 04:00:00 UTC'); // جمعه (w=5)
$nowSat = $satTeh - 12600;
$nowFri = $friTeh - 12600;
$recentFull = ['day' => '2026-11-20', 'ok' => true, 'utc' => gmdate('c', $nowSat - 86400)];
$healthyInc = ['ok' => true, 'utc' => gmdate('c', $nowSat - 600)];
$staleInc   = ['ok' => true, 'utc' => gmdate('c', $nowSat - 30000)];
if (mys_full_due($recentFull, $healthyInc, $nowSat, $satTeh) !== false) { fwrite(STDERR, "weekly midweek skip\n"); exit(1); }
if (mys_full_due($recentFull, $staleInc, $nowSat, $satTeh) !== true) { fwrite(STDERR, "weekly stale fallback\n"); exit(1); }
if (mys_full_due($recentFull, ['ok' => false, 'utc' => gmdate('c', $nowSat - 60)], $nowSat, $satTeh) !== true) { fwrite(STDERR, "weekly fail fallback\n"); exit(1); }
if (mys_full_due($recentFull, ['ok' => true, 'utc' => gmdate('c', $nowFri - 600)], $nowFri, $friTeh) !== true) { fwrite(STDERR, "weekly friday run\n"); exit(1); }
echo "ok\n";
