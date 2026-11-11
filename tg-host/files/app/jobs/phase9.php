<?php
// Phase 9 — host updates itself, but ONLY on the owner's explicit tap in Telegram (user's decision 1405-08-20:
// «مسیرِ گزینهٔ ۲ را ایجاد کن … فقط یک قفلِ بسیار محکم بگذار که برای به‌روزرسانی حتماً از من اجازهٔ صریح بگیرد»).
//
// Flow: a session (or anyone with write access to the private repo) may only PROPOSE a commit, by writing
//   Memory-Workflow/nightly-chain-independence/tg-deploy-request.json  {"sha": "<40 hex>", "summary": "<text>"}
// The tick reads it (vault token), checks the SHA has a tg-host manifest in the public repo, and DMs the owner a message
// with two buttons. Only the owner's tap on «✅» writes deploy-ref.txt; the cron deploy.php then pins that SHA as before.
//
// ⛔ The lock (why no session can deploy alone):
//   1. Approval comes only from the Telegram account recorded in var/chain-owner.json (the account this host serves);
//      every other sender is ignored. A Claude session cannot tap a button in the owner's Telegram.
//   2. Each proposal gets a fresh single-use nonce bound to that exact SHA; the button carries the nonce, never the SHA.
//   3. A proposal expires after 24 h; a changed request replaces the pending one and the old button stops working.
//   4. Only a full 40-hex SHA whose tg-host/manifest.json exists in the public repo is proposed.
//   5. Every approval, refusal and expiry is announced to the owner and logged; the SHA is never deployed silently.
//   6. The manifest still cannot write deploy*, secrets* or var/ (deploy.php) — this code is the only writer of deploy-ref.txt.
// Lock name: tg-host-deploys-only-on-the-owners-telegram-tap

const TG_DEPLOY_REQ = 'Memory-Workflow/nightly-chain-independence/tg-deploy-request.json';
const TG_DEPLOY_PUBLIC = 'lili2001mua-cmd/Agent-Compute-Public';
const TG_DEPLOY_TTL_S = 86400;
const TG_DEPLOY_POLL_S = 300;

function tg_deploy_owner(): int { return (int) ((tg_read_json(tg_var('chain-owner.json')) ?? [])['id'] ?? 0); }
function tg_deploy_current(): string { return trim((string) @file_get_contents(TG_BASE . '/deploy-ref.txt')); }

/** Tick (CLI): read the proposal, DM the owner once per (sha, nonce). Never writes deploy-ref.txt. */
function tg_deploy_request_tick(): void {
    if (PHP_SAPI !== 'cli' || !function_exists('tg_gh')) return;
    $owner = tg_deploy_owner();
    if (!$owner) return;
    $pf = tg_var('deploy-pending.json');
    $p = tg_read_json($pf) ?? [];
    if (!empty($p['nonce']) && (int) ($p['expires'] ?? 0) < time() && empty($p['expired_said'])) {
        tg_bot_call('sendMessage', ['chat_id' => (string) $owner, 'text' => '⌛ درخواستِ به‌روزرسانیِ هاستِ تلگرام (' . substr((string) $p['sha'], 0, 12) . ') منقضی شد و اجرا نشد.']);
        $p['expired_said'] = true; tg_write_json($pf, $p);
    }
    if (time() - (int) ($p['polled'] ?? 0) < TG_DEPLOY_POLL_S) return;
    $p['polled'] = time(); tg_write_json($pf, $p);
    [$c, $doc] = tg_gh('GET', '/repos/' . TG_REPO . '/contents/' . TG_DEPLOY_REQ . '?ref=main');
    if ($c !== 200) return;
    $req = json_decode((string) base64_decode((string) ($doc['content'] ?? '')), true);
    $sha = strtolower((string) ($req['sha'] ?? ''));
    if (!preg_match('/^[0-9a-f]{40}$/', $sha) || $sha === tg_deploy_current()) return;
    if ($sha === (string) ($p['sha'] ?? '') && (int) ($p['expires'] ?? 0) > time()) return; // already asked
    if ($sha === (string) ($p['refused_sha'] ?? '')) return;                                // owner said no to this exact SHA
    $ch = curl_init('https://raw.githubusercontent.com/' . TG_DEPLOY_PUBLIC . '/' . $sha . '/tg-host/manifest.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
    $raw = curl_exec($ch); $mc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $man = json_decode((string) $raw, true);
    if ($mc !== 200 || !is_array($man) || empty($man['files'])) { tg_log('chain', 'deploy proposal rejected: no tg-host manifest at sha'); return; }
    $nonce = bin2hex(random_bytes(8));
    $summary = mb_substr(trim((string) ($req['summary'] ?? '')), 0, 600);
    $text = "🔐 درخواستِ به‌روزرسانیِ هاستِ تلگرام\n\n" . ($summary !== '' ? $summary . "\n\n" : '')
          . 'نسخه: ' . substr($sha, 0, 12) . ' · ' . (string) ($man['version'] ?? '?') . "\n"
          . 'فقط با لمسِ «✅ تأییدِ استقرار» اجرا می‌شود؛ ۲۴ ساعت اعتبار دارد.';
    $kb = ['inline_keyboard' => [[['text' => '✅ تأییدِ استقرار', 'callback_data' => 'deploy:' . $nonce . ':ok'],
                                   ['text' => '❌ رد', 'callback_data' => 'deploy:' . $nonce . ':no']]]];
    $r = tg_bot_call('sendMessage', ['chat_id' => (string) $owner, 'text' => $text, 'reply_markup' => json_encode($kb, JSON_UNESCAPED_UNICODE)]);
    if (!empty($r['ok'])) {
        tg_write_json($pf, ['sha' => $sha, 'nonce' => $nonce, 'expires' => time() + TG_DEPLOY_TTL_S, 'asked_at' => time(),
                            'polled' => time(), 'refused_sha' => (string) ($p['refused_sha'] ?? '')]);
        tg_log('chain', 'deploy proposal sent to owner');
    }
}

/** Called from the bot poll for callback data «deploy:<nonce>:ok|no». Returns the toast text. */
function tg_deploy_on_callback(int $from, string $data): string {
    if ($from !== tg_deploy_owner() || $from === 0) return '';
    if (!preg_match('/^deploy:([0-9a-f]{16}):(ok|no)$/', $data, $m)) return '';
    $pf = tg_var('deploy-pending.json');
    $p = tg_read_json($pf) ?? [];
    if (empty($p['nonce']) || !hash_equals((string) $p['nonce'], $m[1])) return 'این دکمه دیگر معتبر نیست.';
    if ((int) ($p['expires'] ?? 0) < time()) return 'این درخواست منقضی شده است.';
    $sha = (string) $p['sha'];
    if ($m[2] === 'no') {
        tg_write_json($pf, ['refused_sha' => $sha, 'polled' => time()]);
        tg_log('chain', 'deploy refused by owner');
        return '❌ رد شد؛ هاست عوض نمی‌شود.';
    }
    $ref = TG_BASE . '/deploy-ref.txt';
    $prev = tg_deploy_current();
    file_put_contents($ref . '.tmp', $sha . "\n", LOCK_EX);
    rename($ref . '.tmp', $ref);
    tg_write_json($pf, ['approved_sha' => $sha, 'previous_sha' => $prev, 'approved_at' => time(), 'polled' => time()]);
    tg_log('chain', 'deploy approved by owner');
    return '✅ تأیید شد؛ ظرفِ ۵ دقیقه مستقر می‌شود. نسخهٔ قبلی: ' . substr($prev, 0, 12);
}
