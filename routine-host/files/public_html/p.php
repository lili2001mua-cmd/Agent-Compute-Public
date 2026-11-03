<?php
// Manual package page, linked from Telegram. 32 random bytes in the link; only their sha256 is on disk.
// The page carries no secret: the job, the run id, the runbook path and one instruction.
require_once __DIR__ . '/../app/dispatch.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$t = (string) ($_GET['t'] ?? '');
$p = null;
if (preg_match('/^[a-f0-9]{64}$/', $t)) {
    $h = hash('sha256', $t);
    foreach (glob(R_ROOT . '/var/packs/*.json') ?: [] as $f) {
        if (hash_equals(basename($f, '.json'), $h)) { $p = @json_decode((string) file_get_contents($f), true); break; }
    }
}
if (!$p || ($p['expires'] ?? 0) < time()) { http_response_code(404); echo 'این بسته پیدا نشد یا عمرش تمام شده است.'; exit; }
$text = htmlspecialchars(r_pack_text($p), ENT_QUOTES);
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>بستهٔ دستیِ روتین</title>
<style>
:root{--bg:#fafaf7;--fg:#1d1d1b;--muted:#6b6b66;--card:#fff;--line:#e4e2dc;--accent:#2f6f4f}
@media (prefers-color-scheme:dark){:root{--bg:#161614;--fg:#ecebe6;--muted:#a19f98;--card:#1f1f1c;--line:#33322e;--accent:#7cc39c}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.8 Vazirmatn,Tahoma,sans-serif}
main{max-width:720px;margin:0 auto;padding:24px 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px}
pre{white-space:pre-wrap;direction:rtl;margin:0;font:inherit}
button{margin-top:12px;background:var(--accent);color:#fff;border:0;border-radius:8px;padding:10px 18px;font:inherit;cursor:pointer}
small{color:var(--muted)}
</style></head><body><main>
<h1>بستهٔ دستیِ روتین</h1>
<p>روتینِ <b><?= htmlspecialchars($p['job']) ?></b> خودکار اجرا نشد: <?= htmlspecialchars($p['why']) ?>.</p>
<p>متنِ زیر را کپی کن و در یک سشنِ Claude که مخزنِ خصوصی به آن وصل است بچسبان.</p>
<div class="card"><pre id="t"><?= $text ?></pre></div>
<button onclick="navigator.clipboard.writeText(document.getElementById('t').innerText).then(()=>this.textContent='کپی شد')">کپی</button>
<p><small>وقتی سشن کار را تمام کرد، هاست خودش می‌فهمد و در تلگرام خبر می‌دهد. این صفحه تا <?= r_tehran((int) $p['expires']) ?> به وقتِ تهران باز است.</small></p>
</main></body></html>
