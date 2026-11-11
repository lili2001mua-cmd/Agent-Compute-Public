<?php
// Phase 7 — the tg host writes to the private repo (Agent-Project-Backup notebook public-runner-notebook-1405-08-20.md,
// phase A). Holding-vault token (W-HOST) copied by app/vault-sync.php from cron into ../secrets-extra-vault.php.
// ⛔ Path allowlist (security specialist, panel 1405-08-20): the host writes ONLY the heartbeat and host-owned per-bundle
//    files; never .github/, never a doc, never status.json (split writers). Every write is a sha-conditional PUT; a 409/422
//    re-reads and retries (never overwrites a newer file). The token is never logged.
// Flaw rows: committee-retired-a-working-home-for-a-ghost-one · public-runner-plan-had-four-hidden-gaps

const TG_REPO = 'lili2001mua-cmd/Agent-Project-Backup';
const TG_REPO_ALLOW = [
    '#^Memory-Workflow/nightly-chain-independence/host-beat/tg\.json$#',
    '#^Memory-Workflow/intake-pool/state/telegram/b[0-9]{1,8}/host-[a-z0-9-]{1,40}\.json$#',
    '#^Memory-Workflow/nightly-pool/host/[0-9]{4}-[0-9]{2}-[0-9]{2}(/[a-z0-9-]{1,40})?\.json$#',
];
const TG_BEAT_EVERY_S = 3600;

function tg_gh_token(): string {
    $f = TG_BASE . '/secrets-extra-vault.php';
    if (!is_file($f)) return '';
    $a = include $f;
    return is_array($a) ? (string) ($a['TG_GITHUB_TOKEN'] ?? '') : '';
}

/** [code, decoded body] — never logs the token or the body. */
function tg_gh(string $method, string $path, ?array $body = null): array {
    $tok = tg_gh_token();
    if ($tok === '') return [0, ['message' => 'no github token (vault)']];
    $ch = curl_init('https://api.github.com' . $path);
    $h = ['Authorization: Bearer ' . $tok, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: tg-hohedoc'];
    if ($body !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode((string) $raw, true);
    return [$code, is_array($d) ? $d : []];
}

function tg_repo_allowed(string $path): bool {
    if (str_contains($path, '..') || str_starts_with($path, '.github/')) return false;
    foreach (TG_REPO_ALLOW as $rx) if (preg_match($rx, $path)) return true;
    return false;
}

/** Write one allowlisted file on main. Returns ['ok'=>bool, 'code'=>int, 'tries'=>n]. Re-reads on conflict, never forces. */
function tg_repo_put(string $path, string $content, string $msg): array {
    if (!tg_repo_allowed($path)) return ['ok' => false, 'code' => -1, 'error' => 'path not allowlisted'];
    $url = '/repos/' . TG_REPO . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $path)));
    for ($i = 1; $i <= 3; $i++) {
        [$c, $cur] = tg_gh('GET', $url . '?ref=main');
        $sha = $c === 200 ? (string) ($cur['sha'] ?? '') : '';
        if ($c === 200 && base64_decode((string) ($cur['content'] ?? '')) === $content) return ['ok' => true, 'code' => 200, 'tries' => $i, 'unchanged' => true];
        $body = ['message' => $msg . "\n\nWriter: tools-skill-tester · ci", 'content' => base64_encode($content), 'branch' => 'main'];
        if ($sha !== '') $body['sha'] = $sha;
        [$p, ] = tg_gh('PUT', $url, $body);
        if ($p === 200 || $p === 201) return ['ok' => true, 'code' => $p, 'tries' => $i];
        if (!in_array($p, [409, 422], true)) return ['ok' => false, 'code' => $p, 'tries' => $i];
        usleep(700000 * $i);
    }
    return ['ok' => false, 'code' => 409, 'tries' => 3];
}

/** Hourly heartbeat: proves the host can write (phase A test). Never throws. */
function tg_host_beat(): void {
    if (PHP_SAPI !== 'cli') return;
    $f = tg_var('host-beat.json');
    $last = tg_read_json($f) ?? [];
    if (time() - (int) ($last['at'] ?? 0) < TG_BEAT_EVERY_S) return;
    $manifest = tg_read_json(TG_BASE . '/var/deployed.json') ?? [];
    $doc = ['host' => 'tg', 'at_utc' => gmdate('c'), 'version' => (string) ($manifest['version'] ?? ''),
            'vault' => (tg_read_json(TG_BASE . '/var/vault.json') ?? [])['state'] ?? 'unknown'];
    $r = tg_repo_put('Memory-Workflow/nightly-chain-independence/host-beat/tg.json',
        json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 'tg-host: heartbeat');
    tg_write_json($f, ['at' => time(), 'ok' => $r['ok'], 'code' => $r['code'], 'tries' => $r['tries'] ?? 0]);
    tg_log('chain', 'host beat ' . ($r['ok'] ? 'ok' : 'failed') . ' code=' . $r['code']);
}
