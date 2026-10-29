<?php
// Shared helpers for ops.hohedoc.ir — the holding's host for system workflows (watchdogs, guards, upkeep).
// Its own subdomain by the user's rule (1405-08-07, «هر موضوعِ اصلی یک زیردامنهٔ جدا»): own code, own cron.
// No secrets file of its own. TELEGRAM_BOT_TOKEN alone is borrowed from trainer's secret files on the same
// account, and only by the cron (the web side cannot see sibling folders — open_basedir). No GitHub token,
// no Telegram session, no Drive key.
const OPS_ROOT = __DIR__ . '/..';
const OPS_ALERT_CHAT = -1004359416005;   // trainer control channel — the one the user already watches

// Routines this host watches: id => max hours between two beats. A beat arrives when a push to the private
// repo's main carries that routine's ledger (Memory-Workflow/<id>/…), sent by the private repo's pre-push hook
// (Sys.scripts/ops-beat.py). Same thresholds as the retired nightly-sweep-watchdog.yml.
const OPS_WATCH = [
    'nightly-health-sweep'           => 30,
    'stylistcrm-live-health-daily'   => 30,
    'commercialization-scan'         => 54,
    'invariants-runs'                => 56,
    'nightly-sweep-watchdog-audit'   => 174,
    'claude-utilities-weekly-review' => 174,
];

function ops_var(string $name): string {
    $dir = OPS_ROOT . '/var';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    return $dir . '/' . $name;
}

function ops_state(): array {
    return @json_decode((string) @file_get_contents(ops_var('beats.json')), true) ?: [];
}

function ops_save_state(array $s): void {
    file_put_contents(ops_var('beats.json.tmp'), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename(ops_var('beats.json.tmp'), ops_var('beats.json'));
}

function ops_bot_token(): string {
    // Same file set trainer itself reads: secrets.php plus any secrets-extra*.php (the panel names them).
    $dir = dirname((string) realpath(OPS_ROOT)) . '/trainer.hohedoc.ir';
    $files = [$dir . '/secrets.php'];
    foreach (glob($dir . '/secrets-extra*.php') ?: [] as $x) $files[] = $x;
    foreach (array_reverse($files) as $p) {
        if (!@is_file($p)) continue;
        $t = include $p;
        if (is_array($t) && !empty($t['TELEGRAM_BOT_TOKEN'])) return (string) $t['TELEGRAM_BOT_TOKEN'];
    }
    return '';
}

function ops_telegram(string $text): bool {
    $token = ops_bot_token();
    if ($token === '') return false;
    $ch = curl_init("https://api.telegram.org/bot$token/sendMessage");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['chat_id' => OPS_ALERT_CHAT, 'text' => mb_substr($text, 0, 4096)]]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $body !== false && $code === 200;
}

function ops_log(string $line): void {
    file_put_contents(ops_var('ops.log'), gmdate('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}
