<?php
// Server-side selftest for 4-source backup manifest, 8 MiB chunking, SHA-256 parity,
// and non-primary device rejection (holding-backup BKP-05 Steps 184 & 185).
$tmp = sys_get_temp_dir() . '/ctl_bkp_stream_' . bin2hex(random_bytes(4));
mkdir($tmp . '/app', 0700, true);
mkdir($tmp . '/var', 0700, true);
$stage = $tmp . '/stage';
foreach (['github', 'iran', 'germany', 'drive'] as $s) mkdir("$stage/$s", 0700, true);

copy(__DIR__ . '/../files/app/lib.php', $tmp . '/app/lib.php');
copy(__DIR__ . '/../files/app/primary_anchor.php', $tmp . '/app/primary_anchor.php');
copy(__DIR__ . '/../files/app/backup_stream.php', $tmp . '/app/backup_stream.php');
require $tmp . '/app/backup_stream.php';

$pass = 0; $fail = 0;
function ok(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $msg\n"; }
    else { $fail++; echo "FAIL $msg\n"; }
}

// Seed synthetic artifacts across all 4 sources (including a 17.5 MiB multi-8MiB-chunk artifact)
$bigData = str_repeat(random_bytes(4096), 4480); // 18,350,080 bytes (~17.5 MiB -> 3 chunks of 8M + 8M + 1.5M)
file_put_contents("$stage/germany/germany-vault.hbv1", $bigData);
file_put_contents("$stage/github/Agent-Project-Backup.tar.gz", "GH_TAR_SAMPLE_" . random_bytes(1024));
file_put_contents("$stage/iran/iran-delta.json", '{"ok":true,"items":3}');
file_put_contents("$stage/drive/drive-index.json", '{"account":"BACKUP","files":6182}');

// 1. Before any primary anchor is registered: BOTH manifest and chunk MUST return 403
[$cM0, ] = backup_manifest_for_device('phone1', null, $stage);
[$cC0, ] = backup_chunk_for_device('phone1', ['source' => 'germany', 'artifact' => 'germany-vault.hbv1', 'offset' => 0], $stage);
ok($cM0 === 403, 'backup-manifest refused (403) before primary anchor registered');
ok($cC0 === 403, 'backup-chunk refused (403) before primary anchor registered');

// 2. Register phone1 as sole primary hardware anchor
[$cReg, ] = anchor_register('phone1', ['label' => 'Primary Tablet'], 1795624000);
ok($cReg === 200, 'phone1 registered as primary anchor');

// 3. Non-primary device (phone2) MUST be refused (403) on BOTH manifest and chunk
[$cM2, ] = backup_manifest_for_device('phone2', null, $stage);
[$cC2, ] = backup_chunk_for_device('phone2', ['source' => 'germany', 'artifact' => 'germany-vault.hbv1', 'offset' => 0], $stage);
ok($cM2 === 403, 'non-primary phone2 refused (403) on backup-manifest');
ok($cC2 === 403, 'non-primary phone2 refused (403) on backup-chunk');

// 4. Primary device gets unified manifest for all 4 sources
[$cM1, $bM1] = backup_manifest_for_device('phone1', null, $stage);
ok($cM1 === 200 && $bM1['ok'] === true && $bM1['chunk_max_bytes'] === 8388608, 'primary phone1 gets 4-source manifest with 8 MiB chunk cap');
ok(count($bM1['sources']) === 4 && $bM1['sources']['germany']['ready'] && $bM1['sources']['github']['ready'] && $bM1['sources']['iran']['ready'] && $bM1['sources']['drive']['ready'], 'all 4 sources (github, iran, germany, drive) reported ready');

// 5. Security boundary checks: oversized chunk (> 8 MiB), path traversal, unknown source, out-of-range offset
[$cOver, ] = backup_chunk_for_device('phone1', ['source' => 'germany', 'artifact' => 'germany-vault.hbv1', 'offset' => 0, 'length' => 8388609], $stage);
ok($cOver === 400, 'chunk length > 8 MiB refused (400)');
[$cTrav, ] = backup_chunk_for_device('phone1', ['source' => 'germany', 'artifact' => '../secrets.php', 'offset' => 0], $stage);
ok($cTrav === 400, 'artifact path traversal refused (400)');
[$cBadSrc, ] = backup_chunk_for_device('phone1', ['source' => 'telegram', 'artifact' => 'a.bin', 'offset' => 0], $stage);
ok($cBadSrc === 400, 'unknown source refused (400)');
[$cOor, ] = backup_chunk_for_device('phone1', ['source' => 'iran', 'artifact' => 'iran-delta.json', 'offset' => 999999], $stage);
ok($cOor === 416, 'out-of-range offset refused (416)');

// 6. Multi-chunk 8 MiB streaming + per-chunk SHA-256 + full-file SHA-256 reassembly parity
$offset = 0;
$reassembled = '';
$chunks = 0;
$wantFileSha = hash('sha256', $bigData);
$allChunkShasOk = true;
while (true) {
    [$cc, $bc] = backup_chunk_for_device('phone1', [
        'source' => 'germany',
        'artifact' => 'germany-vault.hbv1',
        'offset' => $offset,
        'length' => BACKUP_MAX_CHUNK_BYTES,
    ], $stage);
    if ($cc !== 200 || empty($bc['ok'])) { $allChunkShasOk = false; break; }
    $rawChunk = base64_decode($bc['data_b64'], true);
    if ($rawChunk === false || hash('sha256', $rawChunk) !== $bc['chunk_sha256'] || $bc['file_sha256'] !== $wantFileSha) {
        $allChunkShasOk = false;
        break;
    }
    $reassembled .= $rawChunk;
    $offset = (int) $bc['next_offset'];
    $chunks++;
    if (!empty($bc['eof'])) break;
}
ok($chunks === 3 && $allChunkShasOk, '17.5 MiB artifact streamed in exactly 3 resumable <=8 MiB chunks with valid per-chunk SHA-256');
ok(strlen($reassembled) === strlen($bigData) && hash('sha256', $reassembled) === $wantFileSha, 'reassembled artifact matches original byte-for-byte and SHA-256');

// 7. Single-chunk streaming verified across github, iran, drive
foreach ([['github', 'Agent-Project-Backup.tar.gz'], ['iran', 'iran-delta.json'], ['drive', 'drive-index.json']] as [$s, $art]) {
    [$cs, $bs] = backup_chunk_for_device('phone1', ['source' => $s, 'artifact' => $art, 'offset' => 0], $stage);
    $raw = $cs === 200 ? base64_decode($bs['data_b64'], true) : false;
    $orig = file_get_contents("$stage/$s/$art");
    ok($cs === 200 && $bs['eof'] === true && $raw === $orig && $bs['chunk_sha256'] === hash('sha256', $orig), "source=$s artifact=$art streamed with exact SHA-256");
}

// Cleanup
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
@rmdir($tmp);

echo "\nTOTAL: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
