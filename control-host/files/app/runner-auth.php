<?php
// امضای OIDC ِ گیت‌هاب‌اکشنز (RS256) برای درِ رانر (public_html/runner.php) — همان روشِ studio-host/files/app/lib.php .
// پذیرفته فقط: مخزنِ خصوصیِ هلدینگ، شاخهٔ main ، ورک‌فلوی button-chain.yml ، audience = control.hohedoc.ir .
// ⛔ این در هیچ رازی برنمی‌گرداند؛ فقط یک کارِ نام‌دار (درخواستِ گامِ ۳) را می‌پذیرد.
const CTL_OIDC_ISS = 'https://token.actions.githubusercontent.com';
const CTL_OIDC_AUD = 'control.hohedoc.ir';
const CTL_RUNNER_REPO = 'lili2001mua-cmd/agent-project-backup';
const CTL_RUNNER_WORKFLOWS = ['button-chain.yml'];

function ctl_b64url(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function ctl_der_len(int $n): string {
    if ($n < 0x80) return chr($n);
    $b = ''; while ($n > 0) { $b = chr($n & 0xff) . $b; $n >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}
function ctl_jwk_pem(string $n, string $e): string {
    $int = function (string $x) { if (ord($x[0]) > 0x7f) $x = "\0" . $x; return "\x02" . ctl_der_len(strlen($x)) . $x; };
    $rsa = $int(ctl_b64url($n)) . $int(ctl_b64url($e));
    $rsa = "\x30" . ctl_der_len(strlen($rsa)) . $rsa;
    $bit = "\x03" . ctl_der_len(strlen($rsa) + 1) . "\0" . $rsa;
    $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $spki = "\x30" . ctl_der_len(strlen($alg . $bit)) . $alg . $bit;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Pure claim checks (no signature), for the selftest. Returns the workflow file name or null. */
function ctl_runner_claims_ok(array $c, int $now): ?string {
    if (($c['iss'] ?? '') !== CTL_OIDC_ISS || ($c['aud'] ?? '') !== CTL_OIDC_AUD) return null;
    if ((int) ($c['exp'] ?? 0) < $now || (int) ($c['nbf'] ?? $now) > $now + 60) return null;
    if (strtolower((string) ($c['repository'] ?? '')) !== CTL_RUNNER_REPO) return null;
    $wr = strtolower((string) ($c['workflow_ref'] ?? ''));
    foreach (CTL_RUNNER_WORKFLOWS as $w) if ($wr === CTL_RUNNER_REPO . '/.github/workflows/' . $w . '@refs/heads/main') return $w;
    return null;
}

/** Verified claims, or null. */
function ctl_runner_claims(string $jwt): ?array {
    $p = explode('.', $jwt);
    if (count($p) !== 3 || strlen($jwt) > 8000) return null;
    $h = json_decode(ctl_b64url($p[0]), true); $c = json_decode(ctl_b64url($p[1]), true);
    if (!is_array($h) || !is_array($c) || ($h['alg'] ?? '') !== 'RS256' || empty($h['kid'])) return null;
    $now = time();
    $wf = ctl_runner_claims_ok($c, $now);
    if ($wf === null) return null;
    $cache = ctl_var('oidc-jwks.json');
    $jw = @json_decode((string) @file_get_contents($cache), true);
    $find = function ($jw) use ($h) { foreach ($jw['keys'] ?? [] as $k) if (($k['kid'] ?? '') === $h['kid']) return $k; return null; };
    $key = is_array($jw) && ($jw['t'] ?? 0) > $now - 3600 ? $find($jw) : null;
    if (!$key) {
        $ch = curl_init(CTL_OIDC_ISS . '/.well-known/jwks');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'control-hohedoc']);
        $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $r = is_string($raw) ? json_decode($raw, true) : null;
        if ($code !== 200 || !is_array($r)) return null;
        $r['t'] = $now; file_put_contents($cache, json_encode($r), LOCK_EX);
        $key = $find($r);
        if (!$key) return null;
    }
    $pub = openssl_pkey_get_public(ctl_jwk_pem((string) $key['n'], (string) $key['e']));
    if (!$pub || openssl_verify($p[0] . '.' . $p[1], ctl_b64url($p[2]), $pub, OPENSSL_ALGO_SHA256) !== 1) return null;
    $c['workflow'] = $wf;
    return $c;
}
