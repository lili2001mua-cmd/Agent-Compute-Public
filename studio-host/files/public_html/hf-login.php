<?php
// One-time owner login to Higgsfield (app account). A browser cannot send the door header, so this page is
// guarded by a short-lived link: run.php job=hf_login_link gives ?k=<one-time token> (15 minutes).
require __DIR__ . '/../app/hfmcp.php';
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
$k = (string) ($_GET['k'] ?? '');
$f = studio_var('hf-login-link.txt');
$want = is_file($f) ? trim((string) file_get_contents($f)) : '';
if (!preg_match('/^[a-f0-9]{32}$/', $k) || $want === '' || !hash_equals($want, $k) || filemtime($f) < time() - 900) { http_response_code(404); exit; }
@unlink($f);
header('Location: ' . hf_login_url(), true, 302);
