<?php
// sync-routing-digest (GitHub half) on the host — user's decision 1405-08-07: zero Actions. Same output as
// Sys.scripts/sync-routing-digest.py in Agent-Project-Backup: the routing digest (DIGEST block of
// capability-map.md) and the two consumer hooks (HOOK / SHOOK heredocs of apply-routing-kit.sh) go to every repo
// in Sys.registry/routing-consumers.txt. Inputs are read from main every 30 minutes; consumers are touched only
// when the built content changed (or once a day as a drift check). Only a differing file gets a PUT.
require_once __DIR__ . '/stranded.php';   // ops_gh(), OPS_STRANDED_REPO

const OPS_ROUTING_DESTS = ['.claude/routing-digest.md', '.claude/hooks/route-check.sh', '.claude/hooks/routing-session-start.sh'];

function ops_apb_raw(string $path): ?string {
    [$c, $s] = ops_gh('GET', '/repos/' . OPS_STRANDED_REPO . '/contents/' . $path . '?ref=main', null, 'application/vnd.github.raw');
    return $c === 200 ? $s : null;
}

function ops_routing_build(): ?array {
    $map = ops_apb_raw('Sys.identity/routing/capability-map.md');
    $kit = ops_apb_raw('Sys.scripts/apply-routing-kit.sh');
    $cons = ops_apb_raw('Sys.registry/routing-consumers.txt');
    if ($map === null || $kit === null || $cons === null) return null;
    $out = []; $in = false;
    foreach (explode("\n", str_replace("\r\n", "\n", $map)) as $ln) {
        if (str_contains($ln, 'DIGEST:START')) { $in = true; continue; }
        if (str_contains($ln, 'DIGEST:END')) break;
        if ($in && !str_starts_with($ln, '## دایجستِ مسیریابی')) $out[] = $ln;
    }
    $header = ['<!-- managed:apply-routing-kit:v1 — از capability-map مرکزیِ Agent-Project-Backup کپی شده. با sync به‌روز کن. -->',
        '## دایجستِ مسیریابی (نوعِ کار → ایجنت/اسکیل) — نقشهٔ مرکزیِ حساب',
        '> بعضی ایجنت‌ها مرکزی‌اند؛ اگر محلی سینک نشده‌اند، کارِ مربوط را از مخزنِ مرکزی هدایت کن.', ''];
    $files = ['.claude/routing-digest.md' => implode("\n", array_merge($header, $out)) . "\n"];
    foreach (['.claude/hooks/route-check.sh' => 'HOOK', '.claude/hooks/routing-session-start.sh' => 'SHOOK'] as $dest => $mk) {
        if (!preg_match("/<<'$mk'\n(.*?)\n$mk\n/s", $kit, $m)) return null;   // never a silent skip
        $files[$dest] = $m[1] . "\n";
    }
    $owner = explode('/', OPS_STRANDED_REPO)[0];
    $repos = [];
    foreach (explode("\n", $cons) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#') continue;
        $repos[] = str_contains($ln, '/') ? $ln : "$owner/$ln";
    }
    return ['files' => $files, 'repos' => $repos];
}

function ops_routing_tick(): void {
    if (ops_github_token() === '') return;
    $f = ops_var('routing.json');
    $st = @json_decode((string) @file_get_contents($f), true) ?: [];
    if (time() - (int) ($st['checked'] ?? 0) < 1800) return;
    $st['checked'] = time();
    $b = ops_routing_build();
    if ($b === null) {
        $st['error'] = 'inputs unreadable or HOOK/SHOOK block missing';
        if (empty($st['alerted'])) $st['alerted'] = ops_telegram('⚠️ هاستِ ops: ساختِ دایجستِ مسیریابی شکست خورد (ورودی خوانده نشد).');
        file_put_contents($f, json_encode($st)); return;
    }
    $hash = hash('sha256', json_encode($b));
    if ($hash === ($st['hash'] ?? '') && time() - (int) ($st['synced'] ?? 0) < 24 * 3600 && empty($st['failed'])) {
        file_put_contents($f, json_encode($st)); return;
    }
    $res = []; $failed = 0; $changed = 0;
    foreach ($b['repos'] as $repo) {
        foreach ($b['files'] as $dest => $body) {
            [$c, $cur] = ops_gh('GET', "/repos/$repo/contents/$dest?ref=main");
            $sha = null;
            if ($c === 200) {
                if (base64_decode((string) ($cur['content'] ?? '')) === $body) { $res["$repo:$dest"] = 'unchanged'; continue; }
                $sha = $cur['sha'];
            } elseif ($c !== 404) { $res["$repo:$dest"] = "error GET $c"; $failed++; continue; }
            $p = ['message' => "sync: به‌روزرسانیِ خودکارِ routing-digest از نقشهٔ مرکزی (هاستِ ops)", 'content' => base64_encode($body), 'branch' => 'main'];
            if ($sha) $p['sha'] = $sha;
            [$c] = ops_gh('PUT', "/repos/$repo/contents/$dest", $p);
            if ($c === 200 || $c === 201) { $res["$repo:$dest"] = 'updated'; $changed++; }
            else { $res["$repo:$dest"] = "error PUT $c"; $failed++; }
        }
    }
    $st = ['checked' => time(), 'synced' => time(), 'hash' => $hash, 'changed' => $changed, 'failed' => $failed, 'result' => $res];
    ops_log("routing changed=$changed failed=$failed");
    if ($failed) ops_telegram("⚠️ هاستِ ops: هم‌گام‌سازیِ دایجستِ مسیریابی {$failed} خطا داد. https://ops.hohedoc.ir/status.php");
    file_put_contents($f, json_encode($st, JSON_UNESCAPED_UNICODE));
}
