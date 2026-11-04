<?php
// Extra Google Drive accounts for the holding (BACKUP, …) — connected from a phone browser, no GitHub secret.
// Owner of the procedure: mcp-manager (private repo: Agents-General/mcp-manager/references/google-drive-account.md).
//
// Flow (same shape as the Higgsfield login):
//   1) session: run.php job=drive_login_link account=BACKUP  → one-time link, 15 minutes, single use
//   2) owner opens drive-login.php?k=… on the phone. First time only: a small form takes the Google
//      "Web application" client id + secret and stores them in var/ (never in GitHub, never in chat).
//   3) Google consent with the NEW account → oauth/drive-callback.php stores that account's refresh token
//      in var/drive-accounts/<NAME>.json (0600, outside public_html).
//   4) session: job=drive_list account=BACKUP → proves the link with a read-only listing.
// Nothing here prints a token. Since 1405-08-13 the default account (BACKUP) is the holding's only Drive account.
require_once __DIR__ . '/lib.php';

const DA_SCOPE = 'https://www.googleapis.com/auth/drive';
const DA_LINK_TTL = 900;

function da_base(): string { return 'https://studio.hohedoc.ir'; }
function da_redirect_uri(): string { return da_base() . '/oauth/drive-callback.php'; }

function da_name(string $n): string {
    $n = strtoupper(trim($n));
    if (!preg_match('/^[A-Z][A-Z0-9_]{1,20}$/', $n) || $n === 'DEFAULT') throw new RuntimeException('account name: A-Z, 0-9, _ (2-21 chars)');
    return $n;
}

function da_write(string $path, array $data): void {
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
    chmod($tmp, 0600);
    rename($tmp, $path);
}

function da_client(): ?array {
    $c = @json_decode((string) @file_get_contents(studio_var('drive-web-client.json')), true);
    return (is_array($c) && !empty($c['id']) && !empty($c['secret'])) ? $c : null;
}

function da_client_save(string $id, string $secret): void {
    $id = trim($id); $secret = trim($secret);
    if (!preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $id)) throw new RuntimeException('Client ID شبیهِ شناسهٔ گوگل نیست');
    if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $secret)) throw new RuntimeException('Client secret شکلِ درستی ندارد');
    da_write(studio_var('drive-web-client.json'), ['id' => $id, 'secret' => $secret, 'saved' => gmdate('c')]);
}

// Pending one-time link: var/drive-login-<k>.json = {account, t}. Returns the account or null.
function da_pending(string $k, bool $consume = false): ?string {
    if (!preg_match('/^[a-f0-9]{32}$/', $k)) return null;
    $f = studio_var('drive-login-' . $k . '.json');
    $p = @json_decode((string) @file_get_contents($f), true);
    if (!is_array($p) || (time() - (int) ($p['t'] ?? 0)) > DA_LINK_TTL) { @unlink($f); return null; }
    if ($consume) @unlink($f);
    return (string) $p['account'];
}

function da_auth_url(string $k): string {
    $c = da_client();
    if (!$c) throw new RuntimeException('web client not saved');
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $c['id'], 'redirect_uri' => da_redirect_uri(), 'response_type' => 'code',
        'scope' => DA_SCOPE, 'access_type' => 'offline', 'prompt' => 'consent select_account', 'state' => $k]);
}

function da_finish(string $code, string $k): array {
    $acc = da_pending($k, true);
    if ($acc === null) throw new RuntimeException('لینک منقضی یا مصرف‌شده است — از سشن لینکِ تازه بخواه');
    $c = da_client();
    if (!$c || $code === '') throw new RuntimeException('کد یا کلاینت نیست');
    [$st, $r] = studio_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['code' => $code, 'client_id' => $c['id'], 'client_secret' => $c['secret'],
            'redirect_uri' => da_redirect_uri(), 'grant_type' => 'authorization_code']));
    if ($st !== 200 || empty($r['refresh_token'])) throw new RuntimeException("گوگل توکنِ ماندگار نداد ($st)");
    $email = '';
    [$s2, $who] = studio_http('GET', DRIVE_API . '/about?fields=user(emailAddress)', ['Authorization: Bearer ' . $r['access_token']]);
    if ($s2 === 200 && is_array($who)) $email = (string) ($who['user']['emailAddress'] ?? '');
    $dir = studio_var('drive-accounts'); if (!is_dir($dir)) mkdir($dir, 0700, true);
    da_write($dir . '/' . $acc . '.json', ['refresh_token' => $r['refresh_token'], 'email' => $email, 'connected' => gmdate('c')]);
    studio_log('drive-accounts', "connected $acc");
    return ['account' => $acc, 'email' => $email];
}

function da_token(string $acc): string {
    $acc = da_name($acc);
    $a = @json_decode((string) @file_get_contents(studio_var('drive-accounts') . '/' . $acc . '.json'), true);
    $c = da_client();
    if (!is_array($a) || empty($a['refresh_token']) || !$c) throw new RuntimeException("account $acc is not connected");
    [$st, $r] = studio_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => $c['id'], 'client_secret' => $c['secret'],
            'refresh_token' => $a['refresh_token'], 'grant_type' => 'refresh_token']));
    if ($st !== 200 || empty($r['access_token'])) throw new RuntimeException("token refresh failed for $acc ($st)");
    return $r['access_token'];
}

// ---------------------------------------------------------------- door jobs (inline)
function job_drive_login_link(array $a): array {
    $acc = da_name((string) ($a['account'] ?? ''));
    $k = bin2hex(random_bytes(16));
    da_write(studio_var('drive-login-' . $k . '.json'), ['account' => $acc, 't' => time()]);
    return ['open_in_browser' => da_base() . '/drive-login.php?k=' . $k, 'valid_minutes' => DA_LINK_TTL / 60,
        'account' => $acc, 'web_client_saved' => da_client() !== null, 'redirect_uri' => da_redirect_uri()];
}

function job_drive_accounts(array $a): array {
    $out = [];
    foreach (glob(studio_var('drive-accounts') . '/*.json') ?: [] as $f) {
        $d = @json_decode((string) file_get_contents($f), true);
        $out[] = ['account' => basename($f, '.json'), 'email' => $d['email'] ?? '', 'connected' => $d['connected'] ?? ''];
    }
    return ['web_client_saved' => da_client() !== null, 'redirect_uri' => da_redirect_uri(), 'accounts' => $out];
}

// Read-only proof: the account's email and up to 20 names in its My Drive root.
function job_drive_list(array $a): array {
    $tok = da_token((string) ($a['account'] ?? ''));
    $h = ['Authorization: Bearer ' . $tok];
    [$s1, $who] = studio_http('GET', DRIVE_API . '/about?fields=user(emailAddress),storageQuota(limit,usage)', $h);
    [$s2, $ls] = studio_http('GET', DRIVE_API . '/files?' . http_build_query(['q' => "'root' in parents and trashed=false",
        'pageSize' => 20, 'fields' => 'files(id,name,mimeType)']), $h);
    if ($s1 !== 200 || $s2 !== 200) throw new RuntimeException("drive read failed ($s1/$s2)");
    return ['email' => $who['user']['emailAddress'] ?? '', 'quota' => $who['storageQuota'] ?? [], 'root' => $ls['files'] ?? []];
}
