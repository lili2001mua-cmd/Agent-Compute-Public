<?php
// Phase 9 — the host updates itself from a proposal in the private repo, approved by the user IN THE CLAUDE SESSION
// (user's decision 1405-08-21: «تأیید را همین‌جا در سشن از من بگیر — این راه‌حل [دکمهٔ تلگرام] را کلاً کنار بگذار»).
//
// Proposal: Memory-Workflow/nightly-chain-independence/tg-deploy-request.json {"sha": "<40 hex>", "summary": "..."}.
// ⛔ The lock lives where the user approves: the session hook Sys.scripts/lib/host_transfer_gate.py (Agent-Project-Backup)
//    refuses every write of that file unless the user's last real message carries «تأیید انتقال به هاست» (or a consent
//    answer to the session's explicit question). Lock: tg-host-deploys-only-on-the-users-typed-approval.
//    Honest boundary: a writer that bypasses the session (direct GitHub edit) is not stopped here — same as every queue
//    of this holding; the host only refuses malformed SHAs and SHAs without a tg-host manifest in the public repo.
// The host then pins that SHA into deploy-ref.txt; the cron deploy.php deploys it as before (manifest still can never
// write deploy*, secrets* or var/).

const TG_DEPLOY_REQ = 'Memory-Workflow/nightly-chain-independence/tg-deploy-request.json';
const TG_DEPLOY_PUBLIC = 'lili2001mua-cmd/Agent-Compute-Public';
const TG_DEPLOY_POLL_S = 300;

function tg_deploy_current(): string { return trim((string) @file_get_contents(TG_BASE . '/deploy-ref.txt')); }

/** Tick (CLI): apply an approved proposal from the repo. Never throws. */
function tg_deploy_request_tick(): void {
    if (PHP_SAPI !== 'cli' || !function_exists('tg_gh')) return;
    $sf = tg_var('deploy-state.json');
    $st = tg_read_json($sf) ?? [];
    if (time() - (int) ($st['polled'] ?? 0) < TG_DEPLOY_POLL_S) return;
    $st['polled'] = time();
    [$c, $doc] = tg_gh('GET', '/repos/' . TG_REPO . '/contents/' . TG_DEPLOY_REQ . '?ref=main');
    $req = $c === 200 ? json_decode((string) base64_decode((string) ($doc['content'] ?? '')), true) : null;
    $sha = strtolower((string) ($req['sha'] ?? ''));
    $st['last_read_code'] = $c;
    if (!preg_match('/^[0-9a-f]{40}$/', $sha) || $sha === tg_deploy_current() || $sha === (string) ($st['rejected_sha'] ?? '')) { tg_write_json($sf, $st); return; }
    $ch = curl_init('https://raw.githubusercontent.com/' . TG_DEPLOY_PUBLIC . '/' . $sha . '/tg-host/manifest.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
    $raw = curl_exec($ch); $mc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $man = json_decode((string) $raw, true);
    if ($mc !== 200 || !is_array($man) || empty($man['files'])) {
        $st['rejected_sha'] = $sha; $st['rejected_why'] = 'no tg-host manifest at sha'; tg_write_json($sf, $st);
        tg_log('chain', 'deploy proposal rejected: no tg-host manifest at sha');
        return;
    }
    $ref = TG_BASE . '/deploy-ref.txt';
    $prev = tg_deploy_current();
    $ok = file_put_contents($ref . '.tmp', $sha . "\n", LOCK_EX) !== false && rename($ref . '.tmp', $ref);
    tg_write_json($sf, ['polled' => time(), 'last_read_code' => $c, 'applied_sha' => $ok ? $sha : '', 'previous_sha' => $prev,
        'applied_at' => time(), 'write_ok' => $ok, 'manifest_version' => (string) ($man['version'] ?? '')]);
    tg_log('chain', 'deploy proposal applied write_ok=' . ($ok ? 1 : 0));
}
