<?php
// Public endpoint for the sealed sites-host door (app/door.php).
// GET returns readiness booleans only; POST accepts a door-sealed envelope and returns a sealed response.
require __DIR__ . '/../app/door.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
[$code, $out] = door_handle($_SERVER['REQUEST_METHOD'] ?? 'GET', (string) file_get_contents('php://input'));
http_response_code($code);
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
