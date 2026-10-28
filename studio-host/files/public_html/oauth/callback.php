<?php
// OAuth return leg: exchanges the code and stores tokens outside public_html. Prints one line, no UI.
require __DIR__ . '/../../app/hfmcp.php';
header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
try {
    if (!empty($_GET['error'])) throw new RuntimeException('Higgsfield said: ' . $_GET['error'] . ' ' . ($_GET['error_description'] ?? ''));
    $r = hf_finish_login((string) ($_GET['code'] ?? ''), (string) ($_GET['state'] ?? ''));
    echo "ورود انجام شد. این صفحه را ببند.\n", json_encode($r);
} catch (Throwable $e) { http_response_code(400); echo "ورود ناموفق: ", $e->getMessage(); }
