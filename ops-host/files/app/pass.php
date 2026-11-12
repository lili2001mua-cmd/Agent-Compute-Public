<?php
// Door passes over a direct HTTPS call (1405-08-21 · flaw: door-pass-needs-push-to-a-repo-the-session-cannot-reach).
// The studio/tg/apps/trainer hosts accept a pass only as <dir>/<sha256(nonce)>.json on a branch of this public repo and
// hold no GitHub token by design. A Claude cloud session whose git proxy authorizes only the private repo cannot push
// it, so it POSTs the hash here, sealed with the door key of the private repo (the same envelope as bus.php, kind
// "pass" bound into the tag) — sealing proves read access to the private repo, the same proof the bus accepts. This
// host writes the file on branch door-passes with the W-HOST token (ops_github_token()). Only the hash is ever public, as before.
// Entry: public_html/pass.php · client: Agent-Project-Backup Sys.scripts/lib/door_pass.py (no GitHub branch involved).
require_once __DIR__ . '/bus.php';   // bus_keys(), bus_open() — envelope only; nothing here polls or writes a branch

const PASS_REPO = 'lili2001mua-cmd/Agent-Compute-Public';
const PASS_BRANCH = 'door-passes';
const PASS_DIRS = ['studio-auth', 'tg-auth', 'apps-auth', 'trainer-auth'];

/** Write one door pass on PASS_BRANCH (created from main when missing). */
function pass_write(array $p): array {
    $dir = (string) ($p['dir'] ?? ''); $h = (string) ($p['hash'] ?? ''); $exp = (int) ($p['exp'] ?? 0);
    if (!in_array($dir, PASS_DIRS, true)) return [400, ['error' => 'dir not allowed']];
    if (!preg_match('/^[0-9a-f]{64}$/', $h)) return [400, ['error' => 'bad hash']];
    if ($exp <= time() || $exp > time() + 26 * 3600) return [400, ['error' => 'exp out of range']];
    $base = '/repos/' . PASS_REPO;
    [$c] = ops_gh('GET', $base . '/git/ref/heads/' . PASS_BRANCH);
    if ($c === 404) {
        [$cm, $main] = ops_gh('GET', $base . '/git/ref/heads/main');
        if ($cm !== 200) return [502, ['error' => "main ref $cm"]];
        [$cr] = ops_gh('POST', $base . '/git/refs', ['ref' => 'refs/heads/' . PASS_BRANCH, 'sha' => $main['object']['sha']]);
        if ($cr !== 201 && $cr !== 422) return [502, ['error' => "create branch $cr"]];   // 422 = a racing call made it
    } elseif ($c !== 200) return [502, ['error' => "branch $c"]];
    $cp = 0;
    for ($try = 0; $try < 3; $try++) {   // 409 = the branch head moved under us
        [$cp] = ops_gh('PUT', $base . "/contents/$dir/$h.json", ['message' => "$dir: one-day door pass (hash only, via ops pass.php)",
            'content' => base64_encode(json_encode(['exp' => $exp])), 'branch' => PASS_BRANCH]);
        if ($cp === 201 || $cp === 200) { ops_log("pass $dir"); return [200, ['ref' => PASS_BRANCH]]; }
        if ($cp !== 409) break;
        usleep(500000);
    }
    return [502, ['error' => "write $cp"]];
}

/** One HTTP call to public_html/pass.php. GET = readiness probe (no secret); POST = sealed pass request. */
function pass_handle(string $method, string $body): array {
    $keys = bus_keys();
    if ($method === 'GET') return [200, ['pass' => 'ready', 'key' => $keys !== null, 'token' => ops_github_token() !== '']];
    if ($method !== 'POST') return [405, ['error' => 'method']];
    $fF = ops_var('pass-fail.json');   // 20 bad envelopes per 10 minutes close the door, as on the studio host
    $f = @json_decode((string) @file_get_contents($fF), true) ?: ['n' => 0, 't' => 0];
    if (time() - $f['t'] > 600) $f = ['n' => 0, 't' => time()];
    if ($f['n'] >= 20) return [429, ['error' => 'closed for a while']];
    if ($keys === null || ops_github_token() === '') return [503, ['error' => 'host has no door key or token']];
    $env = strlen($body) <= 8192 ? json_decode($body, true) : null;
    $pt = is_array($env) ? bus_open($keys, $env, 'pass') : null;
    $now = time(); $id = (string) ($env['id'] ?? ''); $eexp = (int) ($env['exp'] ?? 0);
    $seenF = ops_var('pass-seen.json');
    $seen = @json_decode((string) @file_get_contents($seenF), true) ?: [];
    foreach ($seen as $k => $t) if ($t < $now - 3600) unset($seen[$k]);
    if ($pt === null || $eexp < $now || $eexp > $now + 900 || isset($seen[$id])) {   // forged, stale or replayed
        $f['n']++; file_put_contents($fF, json_encode($f), LOCK_EX);
        return [403, ['error' => 'refused']];
    }
    $seen[$id] = $now; file_put_contents($seenF, json_encode($seen), LOCK_EX);
    $p = json_decode($pt, true);
    return pass_write(is_array($p) ? $p : []);
}
