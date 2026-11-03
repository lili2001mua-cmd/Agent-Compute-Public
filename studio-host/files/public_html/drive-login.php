<?php
// One-time page to connect an extra Google Drive account (BACKUP, …) from a phone browser.
// Opened only through run.php job=drive_login_link (?k=<32 hex>, 15 minutes, single use).
// First time only it asks for the Google "Web application" client and stores it in var/ (never GitHub, never chat).
require __DIR__ . '/../app/drive_accounts.php';
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
$k = (string) ($_GET['k'] ?? $_POST['k'] ?? '');
$acc = da_pending($k);
if ($acc === null) { http_response_code(404); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { da_client_save((string) ($_POST['id'] ?? ''), (string) ($_POST['secret'] ?? '')); }
    catch (Throwable $e) { $err = $e->getMessage(); }
}
if (da_client() !== null) { header('Location: ' . da_auth_url($k), true, 302); exit; }
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>اتصالِ گوگل‌درایو</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f6f5f2;color:#1d1d1f;font:16px/1.8 Tahoma,"Vazirmatn",sans-serif;display:block}
main{width:100%;max-width:560px;margin:0 auto;padding:24px 16px}
h1{font-size:20px;margin:0 0 8px}p{margin:0 0 12px}
code{direction:ltr;unicode-bidi:isolate;display:inline-block;background:#fff;border:1px solid #ddd;border-radius:6px;padding:2px 6px;font-size:13px;word-break:break-all}
label{display:block;margin:16px 0 6px;font-weight:bold}
input{width:100%;direction:ltr;font:15px monospace;padding:12px;border:1px solid #bbb;border-radius:8px;background:#fff}
button{width:100%;margin-top:20px;padding:14px;border:0;border-radius:10px;background:#1a73e8;color:#fff;font:bold 16px Tahoma,sans-serif}
.err{background:#fde8e8;border:1px solid #f5b5b5;border-radius:8px;padding:10px}
@media (prefers-color-scheme:dark){body{background:#151515;color:#eee}code,input{background:#222;color:#eee;border-color:#444}}
</style></head><body><main>
<h1>اتصالِ اکانتِ <?= $h($acc) ?></h1>
<p>فقط بارِ اول: شناسه و رمزِ کلاینتِ <code>Web application</code> را که در کنسولِ گوگل ساختی این‌جا بچسبان. مستقیم روی هاست ذخیره می‌شود و جای دیگری نمی‌رود.</p>
<p>نشانیِ بازگشتی که در کنسول وارد کرده‌ای باید دقیقاً این باشد:<br><code><?= $h(da_redirect_uri()) ?></code></p>
<?php if ($err !== ''): ?><p class="err"><?= $h($err) ?></p><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="k" value="<?= $h($k) ?>">
<label for="id">شناسهٔ کلاینت <code>Client ID</code></label>
<input id="id" name="id" required spellcheck="false">
<label for="secret">رمزِ کلاینت <code>Client secret</code></label>
<input id="secret" name="secret" type="password" required spellcheck="false">
<button type="submit">ذخیره و ادامه با گوگل</button>
</form>
</main></body></html>
