<?php
// delete-branch + reconcile-branch on the host (user's decision 1405-08-07: zero Actions). Same rules as the two
// workflows in each repo, through the GitHub REST API only, for the five holding repos.
// Door: a request file on Agent-Project-Backup main — Memory-Workflow/branch-ops/queue/<id>.json:
//   {"repo": "stylistcrm", "action": "delete" | "reconcile", "branches": ["claude/x", ...], "dry_run": false}
// Only someone who can already push to main can ask, so the door needs no key of its own. The host answers in
// Memory-Workflow/branch-ops/results/<id>.json (one commit per request). Read every tick (5 min).
require_once __DIR__ . '/stranded.php';   // ops_gh(), OPS_STRANDED_REPO

const OPS_BRANCH_REPOS = ['Agent-Project-Backup', 'stylistcrm', 'stylistdirectory', 'Gemini-Agent-Backup', 'ChatGPT-Agent-Backup'];
const OPS_BRANCH_Q = 'Memory-Workflow/branch-ops/queue';
const OPS_BRANCH_R = 'Memory-Workflow/branch-ops/results';
const OPS_OWNER = 'lili2001mua-cmd';

function ops_repo_ok(string $repo): ?string {
    foreach (OPS_BRANCH_REPOS as $r) if (strcasecmp($r, $repo) === 0) return $r;
    return null;
}

// RESCUE_RE / JUNK_RE come from the repo's own reconcile-branch.yml (rendered from its branch-kit config): one source.
function ops_reconcile_res(string $repo): array {
    [$c, $src] = ops_gh('GET', '/repos/' . OPS_OWNER . "/$repo/contents/.github/workflows/reconcile-branch.yml?ref=main", null, 'application/vnd.github.raw');
    if ($c !== 200) return [null, null];
    $get = fn($k) => preg_match("/^\s*$k:\s*'(.*)'\s*$/m", $src, $m) ? str_replace("''", "'", $m[1]) : null;
    return [$get('RESCUE_RE'), $get('JUNK_RE')];
}

function ops_re(string $ere): string { return '~' . str_replace('~', '\~', $ere) . '~u'; }

function ops_tree_paths(string $repo, string $sha): ?array {
    [$c, $t] = ops_gh('GET', '/repos/' . OPS_OWNER . "/$repo/git/trees/$sha?recursive=1");
    if ($c !== 200 || !empty($t['truncated'])) return null;
    $out = [];
    foreach ($t['tree'] as $e) if ($e['type'] === 'blob') $out[$e['path']] = [$e['mode'], $e['sha']];
    return $out;
}

function ops_branch_one(string $repo, string $action, string $b, bool $dry): array {
    $base = '/repos/' . OPS_OWNER . "/$repo";
    [$c, $info] = ops_gh('GET', $base);
    $default = $info['default_branch'] ?? 'main';
    if ($b === '' || $b === 'main' || $b === $default) return ['branch' => $b, 'ok' => false, 'msg' => 'protected or empty name'];
    [$c, $ref] = ops_gh('GET', "$base/git/ref/heads/" . str_replace('%2F', '/', rawurlencode($b)));
    if ($c !== 200) return ['branch' => $b, 'ok' => false, 'msg' => "branch not found (http $c)"];
    $bsha = $ref['object']['sha'];
    $del = function () use ($base, $b, $dry): array {
        if ($dry) return [true, 'dry-run: safe, not deleted'];
        [$c] = ops_gh('DELETE', "$base/git/refs/heads/" . str_replace('%2F', '/', rawurlencode($b)));
        return [$c === 204, $c === 204 ? 'deleted' : "delete http $c"];
    };
    if ($action === 'delete') {
        [$c, $cmp] = ops_gh('GET', "$base/compare/main..." . rawurlencode($b));
        if ($c !== 200) return ['branch' => $b, 'ok' => false, 'msg' => "compare http $c"];
        if ((int) $cmp['ahead_by'] > 0) return ['branch' => $b, 'ok' => false, 'msg' => "not merged into main ({$cmp['ahead_by']} commits)"];
        [$ok, $m] = $del();
        return ['branch' => $b, 'ok' => $ok, 'msg' => $m];
    }
    // reconcile: files whose path is on the branch but not on main
    [$rescueRe, $junkRe] = ops_reconcile_res($repo);
    if ($rescueRe === null || $junkRe === null) return ['branch' => $b, 'ok' => false, 'msg' => 'RESCUE_RE/JUNK_RE unreadable in reconcile-branch.yml'];
    [$c, $mref] = ops_gh('GET', "$base/git/ref/heads/main");
    if ($c !== 200) return ['branch' => $b, 'ok' => false, 'msg' => "main http $c"];
    $msha = $mref['object']['sha'];
    [$c, $mc] = ops_gh('GET', "$base/git/commits/$msha");
    [$c2, $bc] = ops_gh('GET', "$base/git/commits/$bsha");
    $mt = ops_tree_paths($repo, $mc['tree']['sha'] ?? ''); $bt = ops_tree_paths($repo, $bc['tree']['sha'] ?? '');
    if ($mt === null || $bt === null) return ['branch' => $b, 'ok' => false, 'msg' => 'tree unreadable or truncated'];
    $uniq = array_diff_key($bt, $mt);
    $rescue = []; $unknown = [];
    foreach ($uniq as $p => $e) {
        if (preg_match(ops_re($rescueRe), $p)) $rescue[$p] = $e;
        elseif (!preg_match(ops_re($junkRe), $p)) $unknown[] = $p;
    }
    if ($unknown) return ['branch' => $b, 'ok' => false, 'msg' => 'unknown unique files, refused', 'unknown' => array_slice($unknown, 0, 50)];
    $res = ['branch' => $b, 'unique' => count($uniq), 'rescued' => array_keys($rescue)];
    if ($dry) return $res + ['ok' => true, 'msg' => 'dry-run: safe, nothing rescued or deleted'];
    if ($rescue) {
        $tree = [];
        foreach ($rescue as $p => [$mode, $sha]) $tree[] = ['path' => $p, 'mode' => $mode, 'type' => 'blob', 'sha' => $sha];
        [$c, $nt] = ops_gh('POST', "$base/git/trees", ['base_tree' => $mc['tree']['sha'], 'tree' => $tree]);
        if ($c !== 201) return $res + ['ok' => false, 'msg' => "tree http $c"];
        [$c, $nc] = ops_gh('POST', "$base/git/commits", ['message' => "rescue: preserve unique docs/sql from $b before deletion\n\nWriter: tools-skill-tester · ops-host", 'tree' => $nt['sha'], 'parents' => [$msha]]);
        if ($c !== 201) return $res + ['ok' => false, 'msg' => "commit http $c"];
        [$c] = ops_gh('PATCH', "$base/git/refs/heads/main", ['sha' => $nc['sha'], 'force' => false]);
        if ($c !== 200) return $res + ['ok' => false, 'msg' => "main moved or refused (http $c); retry the request"];
    }
    [$ok, $m] = $del();
    return $res + ['ok' => $ok, 'msg' => $m];
}

function ops_branch_tick(): void {
    if (ops_github_token() === '') return;
    $apb = '/repos/' . OPS_STRANDED_REPO;
    [$c, $q] = ops_gh('GET', "$apb/contents/" . OPS_BRANCH_Q . '?ref=main');
    $st = @json_decode((string) @file_get_contents(ops_var('branch-ops.json')), true) ?: ['done' => []];
    $st['at'] = time(); $st['queue_http'] = $c;
    if ($c === 200 && is_array($q)) {
        [$rc, $rs] = ops_gh('GET', "$apb/contents/" . OPS_BRANCH_R . '?ref=main');
        $have = [];
        if ($rc === 200 && is_array($rs)) foreach ($rs as $f) $have[$f['name']] = true;
        foreach ($q as $f) {
            $id = $f['name'];
            if (!preg_match('/^[A-Za-z0-9._-]+\.json$/', $id) || isset($have[$id]) || isset($st['done'][$id])) continue;
            [$c, $raw] = ops_gh('GET', "$apb/contents/" . OPS_BRANCH_Q . "/$id?ref=main", null, 'application/vnd.github.raw');
            $req = json_decode((string) $raw, true);
            $repo = is_array($req) ? ops_repo_ok((string) ($req['repo'] ?? '')) : null;
            $action = $req['action'] ?? '';
            $out = ['id' => $id, 'at' => gmdate('c'), 'repo' => $repo, 'action' => $action, 'dry_run' => (bool) ($req['dry_run'] ?? false), 'items' => []];
            if (!$repo || !in_array($action, ['delete', 'reconcile'], true) || empty($req['branches']) || !is_array($req['branches'])) {
                $out['error'] = 'bad request: repo must be one of the five, action delete|reconcile, branches a list';
            } else {
                foreach (array_slice($req['branches'], 0, 30) as $b) $out['items'][] = ops_branch_one($repo, $action, trim((string) $b), $out['dry_run']);
            }
            $out['ok'] = empty($out['error']) && !in_array(false, array_column($out['items'], 'ok'), true);
            [$pc] = ops_gh('PUT', "$apb/contents/" . OPS_BRANCH_R . "/$id", [
                'message' => "branch-ops result: $id\n\nWriter: tools-skill-tester · ops-host",
                'content' => base64_encode(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"),
                'branch' => 'main']);
            $st['done'][$id] = ['ok' => $out['ok'], 'put' => $pc];
            ops_log("branch-ops $id ok=" . (int) $out['ok'] . " put=$pc");
            if (!$out['ok']) ops_telegram("⚠️ هاستِ ops: درخواستِ برنچ «{$id}» کامل انجام نشد. نتیجه: " . OPS_BRANCH_R . "/$id");
        }
    }
    $st['done'] = array_slice($st['done'], -200, null, true);
    file_put_contents(ops_var('branch-ops.json'), json_encode($st));
}

// One probe a day: can the token read and open PRs on each of the five repos? Nothing is written (a PR for a
// branch that does not exist answers 422 when allowed, 403 when not). Booleans only.
function ops_gh_probe(): void {
    $f = ops_var('gh-probe.json');
    $st = @json_decode((string) @file_get_contents($f), true) ?: [];
    if (ops_github_token() === '' || time() - (int) ($st['at'] ?? 0) < 24 * 3600) return;
    $st = ['at' => time(), 'repos' => []];
    foreach (OPS_BRANCH_REPOS as $r) {
        [$c1] = ops_gh('GET', '/repos/' . OPS_OWNER . "/$r/contents/.github?ref=main");
        [$c2] = ops_gh('POST', '/repos/' . OPS_OWNER . "/$r/pulls", ['title' => 'ops probe', 'head' => 'ops-probe-no-such-branch', 'base' => 'main']);
        $st['repos'][$r] = ['read' => $c1 === 200, 'pr_write' => $c2 === 422, 'http' => [$c1, $c2]];
    }
    file_put_contents($f, json_encode($st));
    $bad = array_keys(array_filter($st['repos'], fn($x) => !$x['read'] || !$x['pr_write']));
    if ($bad) ops_telegram('⚠️ هاستِ ops: توکنِ گیت‌هاب روی این مخزن‌ها دسترسیِ کامل ندارد: ' . implode('، ', $bad));
}
