<?php
// Second approval road for a host self-update (phase 9). The owner's DM carries a single-use link with the proposal nonce;
// GET shows the proposal, only a POST of the same nonce writes deploy-ref.txt. Works even when the bot's button poll does
// not hear the tap. The nonce is 64-bit random, lives only in the owner's Telegram DM, expires in 24 h, dies on first use,
// and wrong guesses lock the page for 10 minutes after 5 tries. Lock: tg-host-deploys-only-on-the-owners-telegram-tap
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
$root = dirname(__DIR__);
$var = $root . '/var';
$rj = fn($p) => is_file($p) ? (json_decode((string) file_get_contents($p), true) ?: []) : [];
$wj = function ($p, $d) { file_put_contents($p . '.tmp', json_encode($d), LOCK_EX); rename($p . '.tmp', $p); };
$lockF = $var . '/approve-guard.json';
$g = $rj($lockF);
$fails = array_values(array_filter((array) ($g['fails'] ?? []), fn($t) => $t > time() - 600));
$nonce = (string) ($_POST['n'] ?? $_GET['n'] ?? '');
$p = $rj($var . '/deploy-pending.json');
$valid = preg_match('/^[0-9a-f]{16}$/', $nonce) && !empty($p['nonce']) && hash_equals((string) $p['nonce'], $nonce)
    && (int) ($p['expires'] ?? 0) > time();
$msg = ''; $title = 'به‌روزرسانیِ هاستِ تلگرام';
if (count($fails) >= 5) { $msg = 'تلاشِ نادرستِ زیاد؛ ۱۰ دقیقهٔ دیگر دوباره امتحان کن.'; $valid = false; }
elseif (!$valid) {
    if ($nonce !== '') { $fails[] = time(); $wj($lockF, ['fails' => $fails]); }
    $msg = 'این لینک معتبر نیست یا منقضی شده است.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'ok') {
    $sha = (string) $p['sha'];
    $ref = $root . '/deploy-ref.txt';
    $prev = trim((string) @file_get_contents($ref));
    $okw = file_put_contents($ref . '.tmp', $sha . "\n", LOCK_EX) !== false && rename($ref . '.tmp', $ref);
    $wj($var . '/deploy-pending.json', ['approved_sha' => $sha, 'previous_sha' => $prev, 'approved_at' => time(), 'via' => 'link', 'polled' => time(), 'write_ok' => $okw]);
    $msg = $okw ? '✅ تأیید شد. ظرفِ ۵ دقیقه مستقر می‌شود.' : '⚠️ تأیید ثبت شد ولی نوشتنِ فایلِ نسخه نشد؛ به Claude بگو.';
    $valid = false;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'no') {
    $wj($var . '/deploy-pending.json', ['refused_sha' => (string) $p['sha'], 'polled' => time()]);
    $msg = '❌ رد شد؛ هاست عوض نمی‌شود.'; $valid = false;
}
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($title) ?></title>
<style>
:root{--bg:#f6f7f9;--card:#fff;--ink:#1d2330;--muted:#5b6475;--ok:#11845b;--no:#b3261e}
@media (prefers-color-scheme:dark){:root{--bg:#12151b;--card:#1b2029;--ink:#e8ebf1;--muted:#a3abba}}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.7 Tahoma,"Vazirmatn",system-ui,sans-serif}
main{box-sizing:border-box;width:100%;max-width:560px;margin:0 auto;padding:24px 16px}
.card{background:var(--card);border-radius:14px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
h1{font-size:20px;margin:0 0 12px}.muted{color:var(--muted);font-size:14px}
form{display:flex;flex-direction:column;gap:12px;margin-top:16px}
button{width:100%;border:0;border-radius:10px;padding:14px;font:inherit;font-size:17px;color:#fff;cursor:pointer}
.ok{background:var(--ok)}.no{background:var(--no)}code{direction:ltr;unicode-bidi:isolate}
</style></head><body><main><div class="card">
<h1><?= $e($title) ?></h1>
<?php if ($msg !== ''): ?><p><?= $e($msg) ?></p>
<?php else: ?>
<p>نسخهٔ <code><?= $e(substr((string) $p['sha'], 0, 12)) ?></code> برای هاستِ تلگرام پیشنهاد شده است.</p>
<p class="muted">فقط با تأییدِ تو مستقر می‌شود. این لینک یک‌بارمصرف است.</p>
<form method="post"><input type="hidden" name="n" value="<?= $e($nonce) ?>">
<button class="ok" name="do" value="ok">✅ تأییدِ استقرار</button>
<button class="no" name="do" value="no">❌ رد</button></form>
<?php endif; ?>
</div></main></body></html>
