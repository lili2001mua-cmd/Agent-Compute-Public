<?php
// Door-pass door (app/pass.php): GET → readiness, POST → sealed pass request. Answers JSON; never echoes a secret.
require __DIR__ . '/../app/lib.php';
require __DIR__ . '/../app/pass.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
[$code, $out] = pass_handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) file_get_contents('php://input'));
http_response_code($code);
echo json_encode($out, JSON_UNESCAPED_SLASHES);
