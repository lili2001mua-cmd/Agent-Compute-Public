<?php
// delete-branch + reconcile-branch for the site repos (user's decision 1405-08-08: everything that talks to or
// controls the team's sites lives on sites.hohedoc.ir). Ported from ops-host/files/app/branchq.php, same rules:
// only fully merged branches are deleted; reconcile rescues RESCUE_RE files into main, refuses any unknown unique
// file; never main. Same door as before, so no document changes its path:
//   Agent-Project-Backup main — Memory-Workflow/branch-ops/queue/<id>.json
//   {"repo": "stylistcrm", "action": "delete" | "reconcile", "branches": ["claude/x", ...], "dry_run": false}
// This host answers only requests whose repo is a site repo (sites.php); ops.hohedoc.ir answers the others.
// Only someone who can already push to main can ask, so the door needs no key of its own.
require_once __DIR__ . '/lib.php';

const SB_DOOR_REPO = 'Agent-Project-Backup';
const SB_Q = 'Memory-Workflow/branch-ops/queue';
const SB_R = 'Memory-Workflow/branch-ops/results';

function sb_repos(): array {
    $r = [];
    foreach (dh_sites() as $s) $r[strtolower($s['repo'])] = $s['repo'];
    return $r;
}

function sb_gh(string $method, string $path, ?array $payload = null, string $accept = 'application/vnd.github+json'): array {
    $h = dh_gh_headers($accept);
    $body = null;
    if ($payload !== null) { $h[] = 'Content-Type: application/json'; $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    [$code, $raw] = dh_http($method, DH_GH_API . $path, $h, $body);
    return [$code, $accept === 'application/vnd.github.raw' ? (string) $raw : json_decode((string) $raw, true)];
}

function sb_ref(string $b): string { return str_replace('%2F', '/', rawurlencode($b)); }

function sb_reconcile_res(string $base): array {
    [$c, $src] = sb_gh('GET', "$base/contents/.github/workflows/reconcile-branch.yml?ref=main", null, 'application/vnd.github.raw');
    if ($c !== 200) return [null, null];
    $get = fn($k) => preg_match("/^\s*$k:\s*'(.*)'\s*$/m", $src, $m) ? str_replace("''", "'", $m[1]) : null;
    return [$get('RESCUE_RE'), $get('JUNK_RE')];
}

function sb_re(string $ere): string { return '~' . str_replace('~', '\~', $ere) . '~u'; }

function sb_tree(string $base, string $sha): ?array {
    [$c, $t] = sb_gh('GET', "$base/git/trees/$sha?recursive=1");
    if ($c !== 200 || !empty($t['truncated'])) return null;
    $out = [];
    foreach ($t['tree'] as $e) if ($e['type'] === 'blob') $out[$e['path']] = [$e['mode'], $e['sha']];
    return $out;
}

function sb_branch_one(string $repo, string $action, string $b, bool $dry): array {
    $base = '/repos/' . dh_owner() . "/$repo";
    [$c, $info] = sb_gh('GET', $base);
    $default = $info['default_branch'] ?? 'main';
    if ($b === '' || $b === 'main' || $b === $default) return ['branch' => $b, 'ok' => false, 'msg' => 'protected or empty name'];
    [$c, $ref] = sb_gh('GET', "$base/git/ref/heads/" . sb_ref($b));
    if ($c !== 200) return ['branch' => $b, 'ok' => false, 'msg' => "branch not found (http $c)"];
    $bsha = $ref['object']['sha'];
    $del = function () use ($base, $b, $dry): array {
        if ($dry) return [true, 'dry-run: safe, not deleted'];
        [$c] = sb_gh('DELETE', "$base/git/refs/heads/" . sb_ref($b));
        return [$c === 204, $c === 204 ? 'deleted' : "delete http $c"];
    };
    if ($action === 'delete') {
        [$c, $cmp] = sb_gh('GET', "$base/compare/main..." . rawurlencode($b));
        if ($c !== 200) return ['branch' => $b, 'ok' => false, 'msg' => "compare http $c"];
        if ((int) $cmp['ahead_by'] > 0) return ['branch' => $b, 'ok' => false, 'msg' => "not merged into main ({$cmp['ahead_by']} commits)"];
        [$ok, $m] = $del();
        return ['branch' => $b, 'ok' => $ok, 'msg' => $m];
    }
    [$rescueRe, $junkRe] = sb_reconcile_res($base);
    if ($rescueRe === null || $junkRe === null) return ['branch' => $b, 'ok' => false, 'msg' => 'RESCUE_RE/JUNK_RE unreadable in reconcile-branch.yml'];
    [$c, $mref] = sb_gh('GET', "$base/git/ref/heads/main");
    if ($c !== 200) return ['branch' => $b, 'ok' => false, 'msg' => "main http $c"];
    $msha = $mref['object']['sha'];
    [, $mc] = sb_gh('GET', "$base/git/commits/$msha");
    [, $bc] = sb_gh('GET', "$base/git/commits/$bsha");
    $mt = sb_tree($base, $mc['tree']['sha'] ?? ''); $bt = sb_tree($base, $bc['tree']['sha'] ?? '');
    if ($mt === null || $bt === null) return ['branch' => $b, 'ok' => false, 'msg' => 'tree unreadable or truncated'];
    $uniq = array_diff_key($bt, $mt);
    $rescue = []; $unknown = [];
    foreach ($uniq as $p => $e) {
        if (preg_match(sb_re($rescueRe), $p)) $rescue[$p] = $e;
        elseif (!preg_match(sb_re($junkRe), $p)) $unknown[] = $p;
    }
    if ($unknown) return ['branch' => $b, 'ok' => false, 'msg' => 'unknown unique files, refused', 'unknown' => array_slice($unknown, 0, 50)];
    $res = ['branch' => $b, 'unique' => count($uniq), 'rescued' => array_keys($rescue)];
    if ($dry) return $res + ['ok' => true, 'msg' => 'dry-run: safe, nothing rescued or deleted'];
    if ($rescue) {
        $tree = [];
        foreach ($rescue as $p => [$mode, $sha]) $tree[] = ['path' => $p, 'mode' => $mode, 'type' => 'blob', 'sha' => $sha];
        [$c, $nt] = sb_gh('POST', "$base/git/trees", ['base_tree' => $mc['tree']['sha'], 'tree' => $tree]);
        if ($c !== 201) return $res + ['ok' => false, 'msg' => "tree http $c"];
        [$c, $nc] = sb_gh('POST', "$base/git/commits", ['message' => "rescue: preserve unique docs/sql from $b before deletion\n\nWriter: tools-skill-tester · sites-host", 'tree' => $nt['sha'], 'parents' => [$msha]]);
        if ($c !== 201) return $res + ['ok' => false, 'msg' => "commit http $c"];
        [$c] = sb_gh('PATCH', "$base/git/refs/heads/main", ['sha' => $nc['sha'], 'force' => false]);
        if ($c !== 200) return $res + ['ok' => false, 'msg' => "main moved or refused (http $c); retry the request"];
    }
    [$ok, $m] = $del();
    return $res + ['ok' => $ok, 'msg' => $m];
}

// Every tick: answer each queued request for a site repo that has no result yet. Requests for other repos are
// left alone (ops.hohedoc.ir answers them); a request this host cannot parse is left for ops to judge as well.
function dh_branch_tick(): void {
    $door = '/repos/' . dh_owner() . '/' . SB_DOOR_REPO;
    [$c, $q] = sb_gh('GET', "$door/contents/" . SB_Q . '?ref=main');
    $st = dh_state()['branch_ops'] ?? ['done' => []];
    $st['at'] = gmdate('c'); $st['queue_http'] = $c;
    if ($c === 200 && is_array($q)) {
        [$rc, $rs] = sb_gh('GET', "$door/contents/" . SB_R . '?ref=main');
        $have = [];
        if ($rc === 200 && is_array($rs)) foreach ($rs as $f) $have[$f['name']] = true;
        $mine = sb_repos();
        foreach ($q as $f) {
            $id = (string) $f['name'];
            if (!preg_match('/^[A-Za-z0-9._-]+\.json$/', $id) || isset($have[$id]) || isset($st['done'][$id])) continue;
            [, $raw] = sb_gh('GET', "$door/contents/" . SB_Q . "/$id?ref=main", null, 'application/vnd.github.raw');
            $req = json_decode((string) $raw, true);
            $repo = is_array($req) ? ($mine[strtolower((string) ($req['repo'] ?? ''))] ?? null) : null;
            if ($repo === null) continue;                                  // not a site repo: ops answers it
            $action = $req['action'] ?? '';
            $out = ['id' => $id, 'at' => gmdate('c'), 'host' => 'sites', 'repo' => $repo, 'action' => $action,
                    'dry_run' => (bool) ($req['dry_run'] ?? false), 'items' => []];
            if (!in_array($action, ['delete', 'reconcile'], true) || empty($req['branches']) || !is_array($req['branches'])) {
                $out['error'] = 'bad request: action delete|reconcile, branches a list';
            } else {
                foreach (array_slice($req['branches'], 0, 30) as $b) $out['items'][] = sb_branch_one($repo, $action, trim((string) $b), $out['dry_run']);
            }
            $out['ok'] = empty($out['error']) && !in_array(false, array_column($out['items'], 'ok'), true);
            [$pc] = sb_gh('PUT', "$door/contents/" . SB_R . "/$id", [
                'message' => "branch-ops result: $id\n\nWriter: tools-skill-tester · sites-host",
                'content' => base64_encode(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"),
                'branch' => 'main']);
            $st['done'][$id] = ['ok' => $out['ok'], 'put' => $pc];
            dh_log("branch-ops $id ok=" . (int) $out['ok'] . " put=$pc");
        }
    }
    $st['done'] = array_slice($st['done'], -200, null, true);
    dh_state_set('branch_ops', $st);
}
