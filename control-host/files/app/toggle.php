<?php
// Turning one workflow on or off — the three keys move together (HANDBOOK §4 «سه کلید، نه یکی»):
//   1) GitHub state  (PUT /actions/workflows/{file}/enable|disable)
//   2) Sys.registry/workflow-state.yaml       expected_state + reason
//   3) Sys.registry/workflow-execution-controls.json   enabled + reason
// 2 and 3 land in ONE commit with [skip ci] (no Actions minute). If the commit fails, step 1 is undone.
// The user allowed this machine write of the two registry files (answer 9 of the handbook, 1405-08-10).
require_once __DIR__ . '/lib.php';

function ctl_jalali(int $t): string {
    $d = new DateTime('@' . $t); $d->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$gy, $gm, $gd] = [(int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j')];
    $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
    $jy = -1595 + 33 * intdiv($days, 12053); $days %= 12053;
    $jy += 4 * intdiv($days, 1461); $days %= 1461;
    if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + $days % 31; }
    else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + ($days - 186) % 30; }
    return sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
}

function yaml_q(string $s): string { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"'; }

/** New text of workflow-state.yaml with the row of $wf set to $state and $reason. */
function ctl_state_yaml(string $yaml, string $wf, string $state, string $reason, bool $scheduled): string {
    $re = '/^  - file: ' . preg_quote($wf, '/') . '\n((?:    .*\n)*)/m';
    if (preg_match($re, $yaml, $m, PREG_OFFSET_CAPTURE)) {
        $block = $m[1][0];
        $block = preg_match('/^    expected_state: .*$/m', $block)
            ? preg_replace('/^    expected_state: .*$/m', '    expected_state: ' . $state, $block, 1)
            : '    expected_state: ' . $state . "\n" . $block;
        // a reason may continue on lines indented six spaces — replace the whole value, not its first line
        $block = preg_match('/^    reason: .*\n(?:      .*\n)*/m', $block)
            ? preg_replace_callback('/^    reason: .*\n(?:      .*\n)*/m', fn() => '    reason: ' . yaml_q($reason) . "\n", $block, 1)
            : $block . '    reason: ' . yaml_q($reason) . "\n";
        return substr($yaml, 0, $m[1][1]) . $block . substr($yaml, $m[1][1] + strlen($m[1][0]));
    }
    $row = '  - file: ' . $wf . "\n    expected_state: " . $state . "\n    scheduled: " . ($scheduled ? 'true' : 'false')
         . "\n    reason: " . yaml_q($reason) . "\n";
    return rtrim($yaml, "\n") . "\n" . $row;
}

/** New text of workflow-execution-controls.json with workflows[$wf] = {enabled, reason}. */
function ctl_controls_json(string $json, string $wf, bool $enabled, string $reason): ?string {
    $d = json_decode($json, true);
    if (!is_array($d)) return null;
    $d['workflows'] = $d['workflows'] ?? [];
    $d['workflows'][$wf] = array_merge($d['workflows'][$wf] ?? [], ['enabled' => $enabled, 'reason' => $reason,
        'set_by' => 'my-control-project (control.hohedoc.ir)', 'set_at' => ctl_jalali(time())]);
    $out = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // the repo file uses two-space indent; PHP pretty-print uses four
    return preg_replace_callback('/^( +)/m', fn($x) => str_repeat(' ', intdiv(strlen($x[1]), 2)), $out) . "\n";
}

function ctl_commit(array $files, string $message): array {
    $base = '/repos/' . CTL_REPO;
    [$c, $ref] = gh('GET', "$base/git/ref/heads/" . CTL_BRANCH);
    if ($c !== 200) return [false, "ref $c"];
    $head = $ref['object']['sha'];
    [$c, $commit] = gh('GET', "$base/git/commits/$head");
    if ($c !== 200) return [false, "commit $c"];
    $tree = [];
    foreach ($files as $path => $text) $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'content' => $text];
    [$c, $t] = gh('POST', "$base/git/trees", ['base_tree' => $commit['tree']['sha'], 'tree' => $tree]);
    if ($c !== 201) return [false, "tree $c"];
    [$c, $n] = gh('POST', "$base/git/commits", ['message' => $message, 'tree' => $t['sha'], 'parents' => [$head]]);
    if ($c !== 201) return [false, "new commit $c"];
    [$c] = gh('PATCH', "$base/git/refs/heads/" . CTL_BRANCH, ['sha' => $n['sha'], 'force' => false]);
    if ($c !== 200) return [false, "update ref $c (main moved — try again)"];
    return [true, $n['sha']];
}

function ctl_toggle(string $wf, string $action, string $reason): array {
    $enable = $action === 'enable';
    $state = $enable ? 'active' : 'disabled_manually';
    $why = $reason . ' — از اپِ مای کنترل پروجکت، ' . ctl_jalali(time());
    $yaml = gh_file('Sys.registry/workflow-state.yaml');
    $json = gh_file('Sys.registry/workflow-execution-controls.json');
    $wfText = gh_file('.github/workflows/' . $wf) ?? '';
    if ($yaml === null || $json === null) return ['ok' => false, 'error' => 'registry unreadable', 'state' => 'unchanged'];
    if ($enable && preg_match('/^  - file: ' . preg_quote($wf, '/') . '\n(?:    .*\n)*?    retired_to: /m', $yaml)) {
        return ['ok' => false, 'error' => 'retired workflow — never re-enabled', 'state' => 'unchanged'];
    }
    $newYaml = ctl_state_yaml($yaml, $wf, $state, $why, (bool) preg_match('/^\s*schedule:/m', $wfText));
    $newJson = ctl_controls_json($json, $wf, $enable, $why);
    if ($newJson === null) return ['ok' => false, 'error' => 'controls json unreadable', 'state' => 'unchanged'];

    [$c] = gh('PUT', '/repos/' . CTL_REPO . '/actions/workflows/' . rawurlencode($wf) . '/' . ($enable ? 'enable' : 'disable'));
    if ($c !== 204) return ['ok' => false, 'error' => "github $action $c", 'state' => 'unchanged'];

    [$ok, $sha] = ctl_commit(['Sys.registry/workflow-state.yaml' => $newYaml,
                              'Sys.registry/workflow-execution-controls.json' => $newJson],
                             "control: $action $wf [skip ci]\n\n$why\n\nFrom the My-Control-Project app via control.hohedoc.ir.");
    if (!$ok) {
        // undo step 1 so the three keys never disagree
        gh('PUT', '/repos/' . CTL_REPO . '/actions/workflows/' . rawurlencode($wf) . '/' . ($enable ? 'disable' : 'enable'));
        return ['ok' => false, 'error' => "registry commit failed: $sha — GitHub state rolled back", 'state' => 'unchanged'];
    }
    $st = ctl_json_read('status.json', []);   // reflect the change now; the next tick rebuilds it from GitHub
    foreach (($st['workflows'] ?? []) as $i => $w) if ($w['file'] === $wf) { $st['workflows'][$i]['state'] = $enable ? 'active' : 'disabled_manually'; $st['workflows'][$i]['expected'] = $state; }
    ctl_json_write('status.json', $st);
    return ['ok' => true, 'workflow' => $wf, 'action' => $action, 'state' => $state, 'commit' => $sha];
}
