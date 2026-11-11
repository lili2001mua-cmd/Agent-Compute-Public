<?php
// Expiring download of one installable that app_fetch pulled from Drive (1405-08-20). The 128-bit token is the only key;
// it dies after 24 h. Nothing is listed: a wrong token or name is a plain 404.
require __DIR__ . '/../app/jobs.php';
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
$t = (string) ($_GET['t'] ?? ''); $f = (string) ($_GET['f'] ?? '');
try { $dir = apps_out_dir($t); } catch (Throwable $e) { http_response_code(404); exit; }
if (!preg_match(APP_ZIP_RE, $f)) { http_response_code(404); exit; }
$p = "$dir/$f"; $e = @file_get_contents("$dir/.expires");
if (!is_file($p) || $e === false || (int) $e < time()) { http_response_code(404); exit; }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $f . '"');
header('Content-Length: ' . filesize($p));
readfile($p);
