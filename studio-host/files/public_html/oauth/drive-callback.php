<?php
// Google returns here after the owner approves an extra Drive account. Stores the refresh token in
// var/drive-accounts/<NAME>.json via da_finish(); never prints a token.
require __DIR__ . '/../../app/drive_accounts.php';
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');
$ok = false; $msg = '';
if (isset($_GET['error'])) $msg = 'گوگل اجازه نداد: ' . (string) $_GET['error'];
else {
    try { $r = da_finish((string) ($_GET['code'] ?? ''), (string) ($_GET['state'] ?? '')); $ok = true;
        $msg = 'اکانتِ ' . $r['account'] . ($r['email'] !== '' ? ' (' . $r['email'] . ')' : '') . ' وصل شد. به سشن بگو «وصل شد».'; }
    catch (Throwable $e) { $msg = 'وصل نشد: ' . $e->getMessage(); studio_log('drive-accounts', 'callback failed: ' . $e->getMessage()); }
}
if (!$ok) http_response_code(400);
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>اتصالِ گوگل‌درایو</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f6f5f2;color:#1d1d1f;font:17px/1.9 Tahoma,"Vazirmatn",sans-serif}
main{width:100%;max-width:560px;margin:0 auto;padding:32px 16px}
.box{border-radius:12px;padding:18px;border:1px solid <?= $ok ? '#9bd3a8' : '#f5b5b5' ?>;background:<?= $ok ? '#e8f6ec' : '#fde8e8' ?>}
@media (prefers-color-scheme:dark){body{background:#151515;color:#eee}.box{background:#222}}
</style></head><body><main><p class="box"><?= $h($msg) ?></p></main></body></html>
