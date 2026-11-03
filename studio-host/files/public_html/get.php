<?php
// Download of a queued job's output (sd_fetch zip). Needs the same X-Studio-Key header as run.php and an
// unguessable token; the output is deleted after the first complete download, or at expiry (6 h).
require __DIR__ . '/../app/jobs.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');

if (!studio_key_ok()) { http_response_code(404); exit; }
$t = (string) ($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $t)) { http_response_code(404); exit; }
$dir = studio_out_dir($t);
$zip = "$dir/sd-photos.zip";
$exp = (int) @file_get_contents("$dir/.expires");
if (!is_file($zip) || ($exp && $exp < time())) { http_response_code(404); exit; }
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($zip));
header('Content-Disposition: attachment; filename="sd-photos.zip"');
$sent = readfile($zip);
if ($sent === filesize($zip) && !connection_aborted()) studio_rrmdir($dir);
