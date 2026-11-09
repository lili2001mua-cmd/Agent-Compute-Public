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
//   reset(new): bool            shadow only: move shadow/main to new without ff (divergence repair, counted)
//   seen(key): bool, mark(key)  idempotence store
//   result(session, row)        write the answer the session (or a chat with curl) reads
function land_round(array $a, string $mode): array {
    $sum = ['mode' => $mode, 'landed' => 0, 'rebase' => 0, 'invalid' => 0, 'already' => 0, 'external' => 0, 'diverged' => 0, 'refs' => 0];
    if ($mode !== 'shadow') return $sum + ['skipped' => "mode $mode"];
    $tip = ($a['target'])();
    if ($tip === null) { $up = ($a['upstream'])(); if ($up === null || !($a['reset'])($up)) return $sum + ['error' => 'no target']; $tip = $up; }
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
        $cc = ($a['commit'])($e['candidate']);
        if (!$cc || $cc['tree'] !== $e['tree']) {
            ($a['result'])($session, ['state' => 'invalid', 'why' => 'candidate tree differs from evidence', 'evidence' => $sha]);
            ($a['mark'])("ok:$sha"); $sum['invalid']++; continue;
        }
        $cands[] = ['session' => $session, 'ev' => $e, 'sha' => $sha];
    }
    usort($cands, fn($x, $y) => ($x['ev']['ts'] ?? 0) <=> ($y['ev']['ts'] ?? 0));
    $up0 = ($a['upstream'])();
    // CAS loop: land every candidate whose base is the current tip; repeat while something landed.
    do {
        $progress = false;
        foreach ($cands as $i => $c) {
            $e = $c['ev'];
            if (($a['is_ancestor'])($e['candidate'], $tip)) {
                ($a['result'])($c['session'], ['state' => 'already', 'candidate' => $e['candidate'], 'target' => $tip]);
                ($a['mark'])("ok:{$c['sha']}"); unset($cands[$i]); $sum['already']++; continue;
            }
            // Shadow only: writers outside the queue (bots, chat curl) moved main between two submissions. Follow main up
            // to this candidate's base when the base is already on main, so the CAS sees what live main would show.
            if ($mode === 'shadow' && $e['base'] !== $tip && $up0 !== null && ($a['is_ancestor'])($tip, $e['base']) && ($a['is_ancestor'])($e['base'], $up0)) {
                if (($a['ff'])($tip, $e['base'])) { $tip = $e['base']; $sum['external']++; }
            }
            if ($e['base'] !== $tip || !($a['is_ancestor'])($tip, $e['candidate'])) continue;
            if (!($a['ff'])($tip, $e['candidate'])) continue;
            $tip = $e['candidate'];
            ($a['result'])($c['session'], ['state' => 'landed', 'candidate' => $tip]);
            ($a['mark'])("ok:{$c['sha']}"); unset($cands[$i]); $sum['landed']++; $progress = true;
        }
    } while ($progress && $cands);
    foreach ($cands as $c) {
        ($a['result'])($c['session'], ['state' => 'rebase', 'base' => $c['ev']['base'], 'target' => $tip, 'candidate' => $c['ev']['candidate']]);
        $sum['rebase']++;   // not marked: answered again next round until the session rebases or main catches up
    }
    // Shadow: follow main for writers outside the queue.
    $up = ($a['upstream'])();
    if ($up !== null && $up !== $tip) {
        if (($a['is_ancestor'])($tip, $up)) { if (($a['ff'])($tip, $up)) { $sum['external']++; $tip = $up; } }
        elseif (!($a['is_ancestor'])($up, $tip)) { if (($a['reset'])($up)) { $sum['diverged']++; $tip = $up; } }
    }
    $sum['tip'] = $tip;
    return $sum;
}

// ---- host side: GitHub REST adapter + tick ------------------------------------------------------------------
function land_rest_adapter(string $repo): array {
    $base = "/repos/$repo";
    $sh = '/git/ref/heads/' . LAND_SHADOW;
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
        'ff' => function ($old, $new) use ($base, $sh) {
            [$c, $r] = ops_gh('GET', $base . $sh);
            if ($c !== 200 || $r['object']['sha'] !== $old) return false;   // CAS
            [$c] = ops_gh('PATCH', "$base/git/refs/heads/" . LAND_SHADOW, ['sha' => $new, 'force' => false]);
            return $c === 200;
        },
        'reset' => function ($new) use ($base, $sh) {
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

function land_mode(string $repo): string {
    [$c, $src] = ops_gh('GET', "/repos/$repo/contents/Sys.registry/commit-path-flags.json?ref=main", null, 'application/vnd.github.raw');
    if ($c !== 200) return 'off';
    $j = json_decode($src, true);
    $m = $j['flags']['r7_land'] ?? 'off';
    return in_array($m, ['off', 'shadow', 'live'], true) ? $m : 'off';
}

function land_tick(): void {
    $repo = 'lili2001mua-cmd/Agent-Project-Backup';
    $mode = land_mode($repo);
    $state = ['at' => time(), 'mode' => $mode, 'sha256' => hash_file('sha256', __FILE__)];
    if ($mode === 'live') { ops_log('lander: live mode is not enabled in this version'); $mode = 'off'; $state['refused'] = 'live'; }
    if ($mode !== 'off') {
        $t0 = microtime(true);
        $state['round'] = land_round(land_rest_adapter($repo), $mode);
        $state['took_s'] = round(microtime(true) - $t0, 1);
    }
    file_put_contents(ops_var('lander.json'), json_encode($state, JSON_UNESCAPED_SLASHES));
}
