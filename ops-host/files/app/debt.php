<?php
// debt.php — زنگِ دیرکردِ بدهیِ main (ر۹ ِ commit-path-repair ، ۱۴۰۵-۰۸-۱۹).
// Reads Memory-Workflow/invariants-runs/debt-ledger.json from Agent-Project-Backup main (written by Sys.scripts/debt-ledger.py
// after every local invariants run): each red step has an owner and a 48 h deadline. Once an hour this sends ONE Telegram
// alert per step when its deadline has passed, and ONE recovery message when the step leaves the ledger. No guard runs here.
// State: var/debt.json. status.php → debt (counts only).
require_once __DIR__ . '/stranded.php';   // ops_gh(), OPS_STRANDED_REPO

function debt_tick(): void {
    $st = @json_decode((string) @file_get_contents(ops_var('debt.json')), true) ?: ['alerted' => [], 'checked' => 0];
    if (time() - (int) ($st['checked'] ?? 0) < 3300) return;   // ساعتی یک بار
    $st['checked'] = time();
    [$c, $raw] = ops_gh('GET', '/repos/' . OPS_STRANDED_REPO . '/contents/Memory-Workflow/invariants-runs/debt-ledger.json?ref=main', null, 'application/vnd.github.raw');
    if ($c !== 200) { $st['error'] = "ledger http $c"; file_put_contents(ops_var('debt.json'), json_encode($st, JSON_UNESCAPED_UNICODE)); return; }
    $d = json_decode($raw, true);
    $open = is_array($d['open'] ?? null) ? $d['open'] : [];
    $now = time(); $seen = []; $over = 0;
    $st['alerted'] = is_array($st['alerted'] ?? null) ? $st['alerted'] : [];
    foreach ($open as $i) {
        $step = (string) ($i['step'] ?? ''); if ($step === '') continue;
        $k = md5($step); $seen[$k] = true;
        $dl = strtotime((string) ($i['deadline_utc'] ?? '')) ?: PHP_INT_MAX;
        if ($now <= $dl) continue;
        $over++;
        if (isset($st['alerted'][$k])) continue;
        $msg = "⏰ بدهیِ main از مهلتِ ۴۸ ساعته گذشت:\n«{$step}»\nمالک: " . ($i['owner'] ?? 'system-tester')
             . " · قرمز از " . ($i['since_utc'] ?? '?') . "\nکامیت‌ها را نمی‌بندد، ولی تا رفع نشود در هر کامیت دیده می‌شود.";
        if (ops_telegram($msg)) { $st['alerted'][$k] = ['step' => $step, 'at' => $now]; ops_log("debt-alert " . substr($step, 0, 60)); }
    }
    foreach ($st['alerted'] as $k => $a) {
        if (isset($seen[$k])) continue;
        if (ops_telegram("✅ بدهیِ main رفع شد: «" . ($a['step'] ?? '?') . "»")) { unset($st['alerted'][$k]); ops_log("debt-recovered"); }
    }
    $st['open'] = count($open); $st['overdue'] = $over; $st['last_run_utc'] = $d['last_run_utc'] ?? null; unset($st['error']);
    file_put_contents(ops_var('debt.json'), json_encode($st, JSON_UNESCAPED_UNICODE));
}
