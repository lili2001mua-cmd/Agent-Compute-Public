<?php
// My-Sites backups: the holding's site backups live encrypted in Drive (My-Sites/<site>/<kind>/), never in git.
// The private key (RSA-3072) is made here, once, and never leaves var/ (mode 0600). Sessions get only the public key, so they
// can encrypt but never decrypt. A restore test runs here: download, decrypt, compare the plain sha256.
//
// Format MYS2 (same in Sys.scripts/my_sites_upload.py of Agent-Project-Backup) — OpenSSL only, no sodium on this host:
//   "MYS2" | u16 BE length of the wrapped key | RSA-OAEP(SHA-1, MGF1-SHA-1) of a random 32-byte key | plain chunk size u32 BE
//   | chunks: AES-256-GCM(key, iv = 4 zero bytes . u64 BE index, aad = "\x01" on the last chunk else "\x00") . tag (16)
require_once __DIR__ . '/lib.php';

const MYS_MAGIC = 'MYS2';
const MYS_KEY_FILE = 'my-sites-key.json';

function mys_key(bool $create = false): ?array {
    $p = studio_var(MYS_KEY_FILE);
    $k = @json_decode((string) @file_get_contents($p), true);
    if (is_array($k) && !empty($k['sk']) && !empty($k['pk'])) return $k;
    if (!$create) return null;
    $res = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$res || !openssl_pkey_export($res, $sk)) throw new RuntimeException('cannot make the key');
    $pk = openssl_pkey_get_details($res)['key'];
    $k = ['sk' => $sk, 'pk' => $pk, 'id' => substr(hash('sha256', $pk), 0, 16), 'made' => gmdate('c')];
    $fh = @fopen($p, 'x');                                   // 'x': never overwrite an existing key
    if (!$fh) throw new RuntimeException('cannot store the key');
    chmod($p, 0600);
    fwrite($fh, json_encode($k));
    fclose($fh);
    return $k;
}

// Inline: the public key (made on first call). Safe to hand to anyone.
function job_mysites_pubkey(array $a): array {
    $k = mys_key(true);
    $pub = studio_var('my-sites-pub.json');   // public half only, for sites-host (same server account) to encrypt its daily dumps
    if (!is_file($pub)) { file_put_contents($pub, json_encode(['pk' => $k['pk'], 'id' => $k['id']])); @chmod($pub, 0644); }
    return ['key_id' => $k['id'], 'public_key' => base64_encode($k['pk']), 'format' => MYS_MAGIC];   // PEM, base64
}

// Decrypt a MYS2 stream from $in to $out (both file handles; $out may be null). Returns [plain bytes, plain sha256].
function mys_decrypt($in, $out, array $k): array {
    $head = fread($in, 6);
    if (strlen($head) !== 6 || substr($head, 0, 4) !== MYS_MAGIC) throw new RuntimeException('not a MYS2 file');
    $wl = unpack('n', substr($head, 4, 2))[1];
    $wrapped = fread($in, $wl);
    if (!openssl_private_decrypt($wrapped, $key, $k['sk'], OPENSSL_PKCS1_OAEP_PADDING) || strlen($key) !== 32)
        throw new RuntimeException('cannot unwrap the file key');
    $size = unpack('N', fread($in, 4))[1];
    if ($size < 1024 || $size > 16 * 1024 * 1024) throw new RuntimeException('bad chunk size');
    $h = hash_init('sha256'); $n = 0; $i = 0;
    $cur = (string) fread($in, $size + 16);
    while (true) {
        $next = feof($in) ? '' : (string) fread($in, $size + 16);
        $last = ($next === '');
        if (strlen($cur) < 16) throw new RuntimeException("chunk $i truncated");
        $pt = openssl_decrypt(substr($cur, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, "\0\0\0\0" . pack('J', $i),
                              substr($cur, -16), $last ? "\x01" : "\x00");
        if ($pt === false) throw new RuntimeException("chunk $i failed authentication");
        hash_update($h, $pt); $n += strlen($pt);
        if ($out) fwrite($out, $pt);
        if ($last) break;
        $cur = $next; $i++;
    }
    return [$n, hash_final($h)];
}

// Queued (no web time limit): restore test of one My-Sites file. Downloads it, decrypts, and compares the plain
// sha256 with the file's appProperties.sha256_plain. Nothing decrypted is kept.
function run_mysites_restore(array $a): array {
    $id = (string) ($a['file_id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{20,80}$/', $id)) throw new RuntimeException('bad file_id');
    $k = mys_key(false);
    if (!$k) throw new RuntimeException('no My-Sites key on this host');
    $meta = drive_call('GET', '/files/' . $id, ['fields' => 'id,name,size,appProperties,parents']);
    $ap = $meta['appProperties'] ?? [];
    if (($ap['key_id'] ?? '') !== $k['id']) throw new RuntimeException('file was encrypted with another key');
    $tmp = studio_var('tmp-mys-' . bin2hex(random_bytes(4)));
    $fh = fopen($tmp, 'w+b');
    try {
        $ch = curl_init(DRIVE_API . '/files/' . rawurlencode($id) . '?alt=media');
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . drive_token()],
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 1800, CURLOPT_USERAGENT => 'studio-host']);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) throw new RuntimeException("download HTTP $code");
        rewind($fh);
        $enc_sha = hash_file('sha256', $tmp);
        [$n, $sha] = mys_decrypt($fh, null, $k);
    } finally { fclose($fh); @unlink($tmp); }
    $ok = $sha === ($ap['sha256_plain'] ?? '') && $enc_sha === ($ap['sha256_enc'] ?? '');
    $res = ['file_id' => $id, 'name' => $meta['name'] ?? '', 'ok' => $ok, 'plain_bytes' => $n,
            'sha256_plain_match' => $sha === ($ap['sha256_plain'] ?? ''), 'sha256_enc_match' => $enc_sha === ($ap['sha256_enc'] ?? ''),
            'at' => gmdate('c')];
    file_put_contents(studio_var('mysites-restore.json'), json_encode($res, JSON_UNESCAPED_UNICODE));
    studio_log('mysites', "restore $id " . ($ok ? 'ok' : 'FAIL'));
    return $res;
}
