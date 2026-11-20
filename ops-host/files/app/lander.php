<?php
// lander.php — درِ نشستنِ یگانه (ر۷ ِ دفترچهٔ commit-path-repair ، بخشِ ۸-پ: «صف و CAS»). ۱۴۰۵-۰۸-۱۹.
//
// The host runs no guard and no generator (no python, no git, no exec here). It only does a compare-and-swap:
// a session rebases its candidate on fresh main, runs the landing tier from main's own guard versions, and pushes
// an evidence commit to refs/heads/land/<session> whose first parent is the candidate and whose message carries
//   land-evidence: {"v":1,"session":…,"base":<sha>,"candidate":<sha>,"tree":<sha>,"guards":<sha256>,"verdict":"pass","ts":…}
// This file fast-forwards the target to the candidate only while the target still equals that base; otherwise it
// answers «rebase» in the results. Evidence is self-issued: same trust boundary as today's pre-commit hook.
//
// Modes come from Agent-Project-Backup main, Sys.registry/commit-path-flags.json → flags.r7_land:
//   off    — nothing.
//   shadow — writes ONLY refs/heads/shadow/main; main is never touched. After each round, shadow/main follows main
//            when main is a descendant (commits by writers outside the queue: bots, chat curl), counted as "external".
//   live   — not enabled by this version (refused with a log line), so a flag typo cannot write main.
//
// The core (land_round) takes an adapter of closures, so the replay in the private repo runs this very file with a
// local git adapter (Sys.scripts/land-replay.php) and checks its sha256 against the deployed one (status.php).

const LAND_PREFIX = 'land/';
const LAND_SHADOW = 'shadow/main';
// ر۰ ِ PLAN-R7-LIVE (۱۴۰۵-۰۸-۲۰): شاهدی که ts اش بیش از یک روز کهنه است دیگر پرسیده نمی‌شود — یک بار «stale» جواب می‌گیرد
// و علامت می‌خورد؛ ارجاع پاک نمی‌شود (برگشت‌پذیر)، فقط هر دور دوباره پردازش نمی‌شود.
const LAND_STALE_S = 86400;

function land_parse_evidence(string $msg): ?array {
    if (!preg_match('/^land-evidence:\s*(\{.*\})\s*$/m', $msg, $m)) return null;
    $e = json_decode($m[1], true);
    if (!is_array($e)) return null;
    foreach (['session', 'base', 'candidate', 'tree', 'verdict'] as $k) if (!isset($e[$k]) || !is_string($e[$k])) return null;
    foreach (['base', 'candidate', 'tree'] as $k) if (!preg_match('/^[0-9a-f]{40}$/', $e[$k])) return null;
    return $e;
}

// One round over every land/* ref. $a: adapter with closures
//   target(): ?string           current sha of the target ref (null if missing)
//   upstream(): ?string         current sha of main (shadow resync only)
//   land_refs(): array          [refname => sha]
//   commit(sha): ?array         ['parents' => [...], 'tree' => sha, 'message' => str]
//   is_ancestor(a, b): bool     a is an ancestor of (or equal to) b
//   ff(old, new): bool          move the target from old to new, fast-forward only, refuse otherwise
//   reset(new): bool            shadow only: move shadow/main to new without ff (NEVER called in live mode)
//   seen(key): bool, mark(key)  idempotence store
//   result(session, row)        write the answer the session (or a chat with curl) reads
// $allowlist (live mode only): list of allowed session prefixes/names; empty [] ⇒ no session lands in live mode.
function land_round(array $a, string $mode, array $allowlist = []): array {
    $sum = ['mode' => $mode, 'landed' => 0, 'rebase' => 0, 'invalid' => 0, 'already' => 0, 'external' => 0, 'diverged' => 0, 'stale' => 0, 'not_allowed' => 0, 'refs' => 0];
    if (!in_array($mode, ['shadow', 'live'], true)) return $sum + ['skipped' => "mode $mode"];
    $tip = ($a['target'])();
    if ($tip === null) {
        if ($mode === 'live') return $sum + ['error' => 'no target'];
        $up = ($a['upstream'])();
        if ($up === null || !($a['reset'])($up)) return $sum + ['error' => 'no target'];
        $tip = $up;
    }
    $is_allowed = function (string $session) use ($mode, $allowlist): bool {
        if ($mode !== 'live') return true;
        if (!$allowlist) return false;
        foreach ($allowlist as $p) {
            if ($p === '*' || $session === $p || (str_ends_with($p, '*') && str_starts_with($session, substr($p, 0, -1)))) return true;
        }
        return false;
    };
    $cands = [];
    foreach (($a['land_refs'])() as $ref => $sha) {
        $sum['refs']++;
        $c = ($a['commit'])($sha);
        $e = $c ? land_parse_evidence($c['message']) : null;
        $session = substr($ref, strlen(LAND_PREFIX));
        if ($e === null || ($c['parents'][0] ?? '') !== $e['candidate'] || $e['verdict'] !== 'pass') {
            if (!($a['seen'])("bad:$sha")) { ($a['result'])($session, ['state' => 'invalid', 'evidence' => $sha]); ($a['mark'])("bad:$sha"); $sum['invalid']++; }
            continue;
        }
        if (($a['seen'])("ok:$sha")) continue;
        if ((int) ($e['ts'] ?? 0) < time() - LAND_STALE_S) {
            ($a['result'])($session, ['state' => 'stale', 'evidence' => $sha]);
            ($a['mark'])("ok:$sha"); $sum['stale']++; continue;
        }
        if (!$is_allowed($session)) {
            $sum['not_allowed']++;
            continue;
        }
        $cc = ($a['commit'])($e['candidate']);
        if (!$cc || $cc['tree'] !== $e['tree']) {
            ($a['result'])($session, ['state' => 'invalid', 'why' => 'candidate tree differs from evidence', 'evidence' => $sha]);
            ($a['mark'])("ok:$sha"); $sum['invalid']++; continue;
        }
        $cands[] = ['session' => $session, 'ev' => $e, 'sha' => $sha];
    }
    usort($cands, fn($x, $y) => ($x['ev']['ts'] ?? 0) <=> ($y['ev']['ts'] ?? 0));
    $up0 = $mode === 'shadow' ? ($a['upstream'])() : null;
    // CAS loop: land every candidate whose base is the current tip; repeat while something landed.
    do {
        $progress = false;
        foreach ($cands as $i => $c) {
            $e = $c['ev'];
            if (($a['is_ancestor'])($e['candidate'], $tip)) {
                ($a['result'])($c['session'], ['state' => 'already', 'candidate' => $e['candidate'], 'target' => $tip]);
                ($a['mark'])("ok:{$c['sha']}"); unset($cands[$i]); $sum['already']++; continue;
            }
            // Shadow only: writers outside the queue (bots, chat curl) moved main between two submissions.
            if ($mode === 'shadow' && $e['base'] !== $tip && $up0 !== null && ($a['is_ancestor'])($tip, $e['base']) && ($a['is_ancestor'])($e['base'], $up0)) {
                if (($a['ff'])($tip, $e['base'])) { $tip = $e['base']; $sum['external']++; }
            }
            if ($e['base'] !== $tip || !($a['is_ancestor'])($tip, $e['candidate'])) continue;
            if (!($a['ff'])($tip, $e['candidate'])) {
                // Live mode: a direct push moved main mid-round — refresh tip and leave candidate for rebase.
                if ($mode === 'live') { $fresh = ($a['target'])(); if ($fresh !== null) $tip = $fresh; }
                continue;
            }
            $tip = $e['candidate'];
            ($a['result'])($c['session'], ['state' => 'landed', 'candidate' => $tip]);
            ($a['mark'])("ok:{$c['sha']}"); unset($cands[$i]); $sum['landed']++; $progress = true;
        }
    } while ($progress && $cands);
    foreach ($cands as $c) {
        ($a['result'])($c['session'], ['state' => 'rebase', 'base' => $c['ev']['base'], 'target' => $tip, 'candidate' => $c['ev']['candidate']]);
        $sum['rebase']++;   // not marked: answered again next round until the session rebases or main catches up
    }
    // Shadow only: follow main for writers outside the queue. NEVER runs in live mode (no reset on main).
    if ($mode === 'shadow') {
        $up = ($a['upstream'])();
        if ($up !== null && $up !== $tip) {
            if (($a['is_ancestor'])($tip, $up)) { if (($a['ff'])($tip, $up)) { $sum['external']++; $tip = $up; } }
            elseif (!($a['is_ancestor'])($up, $tip)) { if (($a['reset'])($up)) { $sum['diverged']++; $tip = $up; } }
        }
    }
    $sum['tip'] = $tip;
    return $sum;
}

// ---- host side: GitHub REST adapter + tick ------------------------------------------------------------------
function land_rest_adapter(string $repo, string $mode = 'shadow'): array {
    $base = "/repos/$repo";
    $targetRef = $mode === 'live' ? 'main' : LAND_SHADOW;
    $sh = '/git/ref/heads/' . $targetRef;
    $seenFile = function_exists('ops_var') ? ops_var('land-seen.json') : sys_get_temp_dir() . '/land-seen.json';
    $seen = @json_decode((string) @file_get_contents($seenFile), true) ?: [];
    $resFile = function_exists('ops_var') ? ops_var('land-results.json') : sys_get_temp_dir() . '/land-results.json';
    return [
        'target' => function () use ($base, $sh) { [$c, $r] = ops_gh('GET', $base . $sh); return $c === 200 ? $r['object']['sha'] : null; },
        'upstream' => function () use ($base) { [$c, $r] = ops_gh('GET', "$base/git/ref/heads/main"); return $c === 200 ? $r['object']['sha'] : null; },
        'land_refs' => function () use ($base) {
            [$c, $r] = ops_gh('GET', "$base/git/matching-refs/heads/" . LAND_PREFIX);
            $out = [];
            if ($c === 200) foreach ($r as $x) $out[substr($x['ref'], strlen('refs/heads/'))] = $x['object']['sha'];
            return $out;
        },
        'commit' => function ($sha) use ($base) {
            [$c, $r] = ops_gh('GET', "$base/git/commits/$sha");
            return $c === 200 ? ['parents' => array_map(fn($p) => $p['sha'], $r['parents']), 'tree' => $r['tree']['sha'], 'message' => $r['message']] : null;
        },
        'is_ancestor' => function ($x, $y) use ($base) {
            if ($x === $y) return true;
            [$c, $r] = ops_gh('GET', "$base/compare/$x...$y");
            return $c === 200 && in_array($r['status'] ?? '', ['ahead', 'identical'], true);
        },
        'ff' => function ($old, $new) use ($base, $sh, $targetRef) {
            [$c, $r] = ops_gh('GET', $base . $sh);
            if ($c !== 200 || $r['object']['sha'] !== $old) return false;   // CAS
            [$c] = ops_gh('PATCH', "$base/git/refs/heads/" . $targetRef, ['sha' => $new, 'force' => false]);
            return $c === 200;
        },
        'reset' => function ($new) use ($base, $sh, $mode) {
            // ⛔ قفلِ ر۲: حالتِ live هرگز reset نمی‌زند.
            if ($mode === 'live') return false;
            [$c] = ops_gh('GET', $base . $sh);
            if ($c === 404) { [$c] = ops_gh('POST', "$base/git/refs", ['ref' => 'refs/heads/' . LAND_SHADOW, 'sha' => $new]); return $c === 201; }
            [$c] = ops_gh('PATCH', "$base/git/refs/heads/" . LAND_SHADOW, ['sha' => $new, 'force' => true]);   // shadow ref only
            return $c === 200;
        },
        'seen' => function ($k) use (&$seen) { return isset($seen[$k]); },
        'mark' => function ($k) use (&$seen, $seenFile) { $seen[$k] = time(); if (count($seen) > 5000) $seen = array_slice($seen, -4000, null, true); file_put_contents($seenFile, json_encode($seen), LOCK_EX); },
        'result' => function ($session, $row) use ($resFile) {
            $all = @json_decode((string) @file_get_contents($resFile), true) ?: [];
            $row['at'] = gmdate('c');
            $all[$session] = $row;
            file_put_contents($resFile, json_encode($all, JSON_UNESCAPED_SLASHES), LOCK_EX);
        },
    ];
}

function land_config(string $repo): array {
    [$c, $src] = ops_gh('GET', "/repos/$repo/contents/Sys.registry/commit-path-flags.json?ref=main", null, 'application/vnd.github.raw');
    if ($c !== 200) return ['mode' => 'off', 'allowlist' => []];
    $j = json_decode($src, true);
    $m = $j['flags']['r7_land'] ?? 'off';
    $mode = in_array($m, ['off', 'shadow', 'live'], true) ? $m : 'off';
    $al = $j['flags']['r7_live_allowlist'] ?? [];
    return ['mode' => $mode, 'allowlist' => is_array($al) ? array_values(array_filter($al, 'is_string')) : []];
}

function land_mode(string $repo): string {
    return land_config($repo)['mode'];
}

function land_tick(): void {
    $repo = 'lili2001mua-cmd/Agent-Project-Backup';
    $cfg = land_config($repo);
    $mode = $cfg['mode'];
    $state = ['at' => time(), 'mode' => $mode, 'allowlist' => $cfg['allowlist'], 'sha256' => hash_file('sha256', __FILE__)];
    if ($mode !== 'off') {
        $t0 = microtime(true);
        $state['round'] = land_round(land_rest_adapter($repo, $mode), $mode, $cfg['allowlist']);
        $state['took_s'] = round(microtime(true) - $t0, 1);
    }
    file_put_contents(ops_var('lander.json'), json_encode($state, JSON_UNESCAPED_SLASHES));
}
