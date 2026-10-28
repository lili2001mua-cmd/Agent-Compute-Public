<?php
// Higgsfield through its MCP server with the OWNER'S APP ACCOUNT (app credits, same wallet as the
// claude.ai connector) — not the server-side API key (which has its own, separate wallet).
//
// One-time login: the owner opens https://studio.hohedoc.ir/hf-login.php, signs in at Higgsfield, and the
// refresh token lands in var/hf-oauth.json (0600, outside public_html). From then on the host refreshes
// access tokens itself and calls MCP tools on behalf of the account. This is OAuth 2.1 authorization-code
// with PKCE, and the client is identified by a hosted metadata document (no dynamic registration call).
require_once __DIR__ . '/lib.php';

const HF_MCP = 'https://mcp.higgsfield.ai/mcp';
const HF_AUTH = 'https://mcp.higgsfield.ai/oauth2/authorize';
const HF_TOKEN = 'https://mcp.higgsfield.ai/oauth2/token';
const HF_SCOPE = 'openid email offline_access';

function hf_base(): string { return 'https://' . (studio_secret('STUDIO_HOST') ?: 'studio.hohedoc.ir'); }
const HF_REGISTER = 'https://mcp.higgsfield.ai/oauth2/register';

// Higgsfield only trusts redirect URIs of REGISTERED clients (the hosted client-metadata document was refused
// with invalid_redirect_uri), so the host registers itself once (RFC 7591) and keeps the record in var/.
function hf_client(array $opts = []): array {
    $f = studio_var('hf-client.json');
    $c = @json_decode((string) @file_get_contents($f), true);
    if (is_array($c) && !empty($c['client_id']) && !$opts) return $c;
    // Higgsfield keeps a trusted list of redirect URIs; a loopback URI (what CLI clients use) is the usual member.
    $redirects = $opts['redirect_uris'] ?? [hf_redirect()];
    $body = ['client_name' => 'studio.hohedoc.ir', 'client_uri' => hf_base(),
        'grant_types' => $opts['grant_types'] ?? ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code'],
        'response_types' => ['code'], 'token_endpoint_auth_method' => 'none', 'scope' => HF_SCOPE];
    if ($redirects) $body['redirect_uris'] = $redirects;
    [$code, $r] = studio_http('POST', HF_REGISTER, ['Content-Type: application/json', 'Accept: application/json'], json_encode($body));
    if (!in_array($code, [200, 201], true) || !is_array($r) || empty($r['client_id'])) throw new RuntimeException("client registration failed ($code): " . substr(json_encode($r), 0, 300));
    file_put_contents("$f.tmp", json_encode($r)); chmod("$f.tmp", 0600); rename("$f.tmp", $f);
    return $r;
}

// Device flow (RFC 8628): no redirect at all — the host shows a URL + code, the owner approves on any device,
// the host polls the token endpoint. The metadata advertises the grant; the endpoint path is probed.
function hf_device_start(): array {
    $cid = hf_client_id();
    $last = null;
    foreach (['/oauth2/device_authorization', '/oauth2/device/code', '/oauth2/device', '/oauth/device/code'] as $path) {
        [$c, $r] = studio_http('POST', 'https://mcp.higgsfield.ai' . $path, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query(['client_id' => $cid, 'scope' => HF_SCOPE, 'resource' => HF_MCP]));
        $last = "$path → $c " . substr(is_array($r) ? json_encode($r) : (string) $r, 0, 200);
        if ($c === 200 && is_array($r) && !empty($r['device_code'])) {
            $r['started'] = time();
            file_put_contents(studio_var('hf-device.json'), json_encode($r)); chmod(studio_var('hf-device.json'), 0600);
            return ['open_in_browser' => $r['verification_uri_complete'] ?? $r['verification_uri'] ?? null,
                'user_code' => $r['user_code'] ?? null, 'expires_in' => $r['expires_in'] ?? null, 'endpoint' => $path];
        }
    }
    throw new RuntimeException('no device endpoint answered: ' . $last);
}

function hf_device_poll(): array {
    $d = @json_decode((string) @file_get_contents(studio_var('hf-device.json')), true);
    if (!is_array($d) || empty($d['device_code'])) throw new RuntimeException('no device login in progress');
    [$c, $r] = hf_token_post(['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $d['device_code'], 'resource' => HF_MCP]);
    if ($c === 200 && is_array($r) && !empty($r['access_token'])) { hf_save_tokens($r); @unlink(studio_var('hf-device.json')); return ['logged_in' => true, 'has_refresh' => !empty($r['refresh_token'])]; }
    return ['logged_in' => false, 'status' => is_array($r) ? ($r['error'] ?? "http $c") : "http $c"];
}

function hf_client_id(): string { return (string) hf_client()['client_id']; }

// Token-endpoint auth: public client by default; if registration handed us a secret, send it.
function hf_token_post(array $fields): array {
    $c = hf_client();
    $fields['client_id'] = $c['client_id'];
    if (!empty($c['client_secret'])) $fields['client_secret'] = $c['client_secret'];
    return studio_http('POST', HF_TOKEN, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($fields));
}
function hf_redirect(): string { return hf_base() . '/oauth/callback.php'; }
function hf_store(): string { return studio_var('hf-oauth.json'); }

function hf_tokens(): array {
    $t = @json_decode((string) @file_get_contents(hf_store()), true);
    return is_array($t) ? $t : [];
}

function hf_save_tokens(array $t): void {
    $t['saved_at'] = time();
    file_put_contents(hf_store() . '.tmp', json_encode($t)); chmod(hf_store() . '.tmp', 0600); rename(hf_store() . '.tmp', hf_store());
}

// Step 1 of the login: build the authorization URL and remember the PKCE verifier + state (10 minutes).
function hf_login_url(): string {
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $state = bin2hex(random_bytes(16));
    file_put_contents(studio_var('hf-pkce.json'), json_encode(['v' => $verifier, 's' => $state, 't' => time()]));
    chmod(studio_var('hf-pkce.json'), 0600);
    return HF_AUTH . '?' . http_build_query(['response_type' => 'code', 'client_id' => hf_client_id(),
        'redirect_uri' => hf_redirect(), 'scope' => HF_SCOPE, 'state' => $state,
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'resource' => HF_MCP]);
}

// Step 2: the browser comes back with ?code=&state= ; exchange it for tokens.
function hf_finish_login(string $code, string $state): array {
    $p = @json_decode((string) @file_get_contents(studio_var('hf-pkce.json')), true);
    @unlink(studio_var('hf-pkce.json'));
    if (!is_array($p) || time() - ($p['t'] ?? 0) > 600 || !hash_equals($p['s'] ?? '', $state)) throw new RuntimeException('login expired or state mismatch — open hf-login.php again');
    [$c, $r] = hf_token_post(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => hf_redirect(),
        'code_verifier' => $p['v'], 'resource' => HF_MCP]);
    if ($c !== 200 || !is_array($r) || empty($r['access_token'])) throw new RuntimeException("token exchange failed ($c)");
    hf_save_tokens($r);
    return ['logged_in' => true, 'has_refresh' => !empty($r['refresh_token']), 'expires_in' => $r['expires_in'] ?? null];
}

function hf_access_token(bool $force = false): string {
    $t = hf_tokens();
    if (empty($t['access_token'])) throw new RuntimeException('not logged in — owner opens hf-login.php once');
    $exp = ($t['saved_at'] ?? 0) + (int) ($t['expires_in'] ?? 3600);
    if (!$force && time() < $exp - 60) return $t['access_token'];
    if (empty($t['refresh_token'])) throw new RuntimeException('access token expired and no refresh token — log in again');
    [$c, $r] = hf_token_post(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'resource' => HF_MCP]);
    if ($c !== 200 || !is_array($r) || empty($r['access_token'])) throw new RuntimeException("refresh failed ($c) — log in again");
    if (empty($r['refresh_token'])) $r['refresh_token'] = $t['refresh_token'];
    hf_save_tokens($r);
    return $r['access_token'];
}

// One JSON-RPC call over Streamable HTTP. The server may answer plain JSON or an SSE stream; both are handled.
function hf_rpc(string $method, array $params = [], ?string &$session = null): array {
    static $id = 0;
    $body = json_encode(['jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => (object) $params]);
    for ($try = 0; $try < 2; $try++) {
        $h = ['Authorization: Bearer ' . hf_access_token($try > 0), 'Content-Type: application/json',
            'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'];
        if ($session) $h[] = 'Mcp-Session-Id: ' . $session;
        $ch = curl_init(HF_MCP);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'studio-host']);
        $resp = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($code === 401 && $try === 0) continue;
        $hdr = substr($resp, 0, $hs); $raw = substr($resp, $hs);
        if (preg_match('/^mcp-session-id:\s*(\S+)/mi', $hdr, $m)) $session = $m[1];
        if ($code >= 400) throw new RuntimeException("mcp $method → $code " . substr($raw, 0, 300));
        $json = json_decode($raw, true);
        if (!is_array($json)) {                                     // SSE: take the last data: line that parses
            foreach (preg_split('/\r?\n/', $raw) as $line) {
                if (str_starts_with($line, 'data:')) { $j = json_decode(trim(substr($line, 5)), true); if (is_array($j)) $json = $j; }
            }
        }
        if (!is_array($json)) throw new RuntimeException("mcp $method: unreadable answer");
        if (isset($json['error'])) throw new RuntimeException("mcp $method: " . json_encode($json['error'], JSON_UNESCAPED_UNICODE));
        return $json['result'] ?? [];
    }
    throw new RuntimeException('mcp: unauthorized twice');
}

function hf_session(): string {
    static $s = null;
    if ($s === null) {
        $sid = null;
        hf_rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [],
            'clientInfo' => ['name' => 'studio-host', 'version' => '1']], $sid);
        $s = (string) $sid;
        // notifications/initialized carries no id and expects no answer; a 202 is fine, errors are not fatal.
        try { $x = $s; hf_rpc('notifications/initialized', [], $x); } catch (Throwable $e) {}
    }
    return $s;
}

// Door jobs ------------------------------------------------------------------------------------------
// Registers the client now (or reports why it cannot) so the browser leg never hits a blank 500.
function job_hf_register(array $a): array {
    if (!empty($a['reset'])) @unlink(studio_var('hf-client.json'));
    $opts = [];
    if (isset($a['redirect'])) $opts['redirect_uris'] = $a['redirect'] === '' ? [] : [(string) $a['redirect']];
    try { $c = hf_client($opts); return ['registered' => true, 'client_id' => $c['client_id'], 'auth_method' => $c['token_endpoint_auth_method'] ?? null, 'redirect_uris' => $c['redirect_uris'] ?? null]; }
    catch (Throwable $e) { return ['registered' => false, 'error' => $e->getMessage()]; }
}

function job_hf_login_status(array $a): array {
    $t = hf_tokens();
    $c = @json_decode((string) @file_get_contents(studio_var('hf-client.json')), true);
    return ['logged_in' => !empty($t['access_token']), 'has_refresh' => !empty($t['refresh_token']),
        'saved_at' => $t['saved_at'] ?? null, 'client_registered' => !empty($c['client_id']), 'login_url' => hf_base() . '/hf-login.php'];
}

function job_hf_device_start(array $a): array { return hf_device_start(); }
function job_hf_device_poll(array $a): array { return hf_device_poll(); }

function job_hf_tools(array $a): array {
    $sid = hf_session();
    $r = hf_rpc('tools/list', [], $sid);
    return ['tools' => array_map(fn($t) => ['name' => $t['name'] ?? '', 'description' => mb_substr((string) ($t['description'] ?? ''), 0, 160)], $r['tools'] ?? [])];
}

// tool=<name> args=<JSON object> — this is what spends app credits; the prompt must come through Content-Higgsfield.
function job_hf_call(array $a): array {
    $tool = (string) ($a['tool'] ?? '');
    if (!preg_match('/^[a-z0-9_.-]{1,80}$/i', $tool)) throw new RuntimeException('bad tool name');
    $args = json_decode((string) ($a['args'] ?? '{}'));
    if (!is_object($args)) throw new RuntimeException('args must be a JSON object');
    $sid = hf_session();
    return hf_rpc('tools/call', ['name' => $tool, 'arguments' => $args], $sid);
}
