<?php
// One-time intake door for a dispatcher key (user's request 1405-08-08: "write the key in place yourself").
// POST nonce=<n> name=<alias>-<scope> body=<the rtk1 key text>. Accepted only when sha256(nonce) matches
// app/intake.flag ("<sha256> <expires_unix>", committed to the public repo: only the hash is public), the
// flag has not expired, and that nonce has never been used. The key is parsed by the same strict reader as
// tick.php and written to ../keys/<name>.key (0600). Nothing is echoed back except ok + the key's name.
require_once __DIR__ . '/../app/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$no = function (int $code, string $why) { http_response_code($code); echo json_encode(['ok' => false, 'why' => $why]); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $no(405, 'post only');
$flag = preg_split('/\s+/', trim((string) @file_get_contents(__DIR__ . '/../app/intake.flag')));
if (count($flag) !== 2 || !preg_match('/^[a-f0-9]{64}$/', $flag[0])) $no(403, 'door closed');
if (time() > (int) $flag[1]) $no(403, 'door expired');
$nonce = (string) ($_POST['nonce'] ?? '');
if (!hash_equals($flag[0], hash('sha256', $nonce))) $no(403, 'bad nonce');
$used = r_read_json('intake-used.json');
if (isset($used[$flag[0]])) $no(403, 'nonce already used');
$name = (string) ($_POST['name'] ?? '');
$r = r_parse_key((string) ($_POST['body'] ?? ''));
if (isset($r['error'])) $no(400, $r['error']);
if ("{$r['key']['alias']}-{$r['key']['scope']}" !== $name) $no(400, 'name must be alias-scope');
$dir = R_ROOT . '/keys';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
$p = $dir . '/' . $name . '.key';
file_put_contents($p . '.tmp', (string) $_POST['body'], LOCK_EX);
@chmod($p . '.tmp', 0600);
rename($p . '.tmp', $p);
$used[$flag[0]] = time();
r_write_json('intake-used.json', $used);
r_log("intake key $name");
echo json_encode(['ok' => true, 'written' => "keys/$name.key"]);
