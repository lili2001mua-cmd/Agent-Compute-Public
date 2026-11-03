<?php
// One-time download of a database dump made by the dbdump action. The token is 40 random hex characters and
// lives only in the result file of the site's private repo; the file is deleted after the first download or
// after 6 hours, whichever comes first. Anything else answers 404.
require __DIR__ . '/../app/lib.php';
$t = (string) ($_GET['t'] ?? '');
$dir = dh_var('dumps');
$meta = preg_match('/^[0-9a-f]{40}$/', $t) ? @json_decode((string) @file_get_contents("$dir/$t.json"), true) : null;
if (!$meta || ($meta['expires'] ?? 0) < time() || !is_file("$dir/$t.gz")) { http_response_code(404); exit; }
// Claim it first so two simultaneous requests cannot both stream it.
if (!@rename("$dir/$t.gz", "$dir/$t.sending")) { http_response_code(404); exit; }
@unlink("$dir/$t.json");
header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $meta['name']) . '"');
header('Content-Length: ' . filesize("$dir/$t.sending"));
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
readfile("$dir/$t.sending");
@unlink("$dir/$t.sending");
