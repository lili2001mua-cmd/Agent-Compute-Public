<?php
// Local stand-in for api.github.com and the three sync.php bridges (selftest.sh only).
// State lives in $MOCK_DIR; MOCK_MODE=401 makes every GitHub call fail like an expired token.
$dir = getenv('MOCK_DIR');
$uri = parse_url($_SERVER['REQUEST_URI']);
$path = rawurldecode($uri['path']);
parse_str($uri['query'] ?? '', $qs);
$log = fn($s) => file_put_contents("$dir/calls.log", $s . "\n", FILE_APPEND);
$json = function ($code, $v) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($v); exit; };

if (preg_match('#^/(pwa|hub|help)/sync\.php$#', $path, $m)) {
    $body = file_get_contents('php://input');
    if (($_SERVER['HTTP_X_SIGNATURE'] ?? '') !== 'sha256=' . hash_hmac('sha256', $body, 'test-deploy-secret')) $json(403, ['ok' => false, 'error' => 'signature']);
    $req = json_decode($body, true);
    $log("sync {$m[1]} {$req['action']}");
    if ($req['action'] === 'manifest') {
        $start = (int) ($req['start'] ?? 0);
        $all = ['a.php 1', 'b.php 2', 'c.php 3'];
        $json(200, ['ok' => true, 'total' => 3, 'lines' => array_slice($all, $start, 2), 'done' => $start + 2 >= 3, 'next' => $start + 2]);
    }
    $rows = [];
    foreach ($req['files'] as $f) $rows[] = is_array($f)
        ? ['repo' => $f['repo'], 'ok' => hash('sha256', base64_decode($f['b64'])) === $f['sha256'], 'sha256' => $f['sha256']]
        : ['repo' => $f, 'ok' => true];
    $json(200, ['ok' => true, 'results' => $rows]);
}
if ($path === '/probe.php') { $log('run probe ' . ($qs['x'] ?? '')); echo 'ran-ok'; exit; }
if (file_exists("$dir/mode-401")) $json(401, ['message' => 'Bad credentials']);
if ($path === '/user') $json(200, ['login' => 'o']);
$p = '/repos/o/stylistcrm';
if ($path === "$p/contents/deploy-queue") $json(200, [
    ['type' => 'file', 'name' => 'old.json', 'path' => 'deploy-queue/old.json'],
    ['type' => 'file', 'name' => 'new.json', 'path' => 'deploy-queue/new.json']]
    + (is_file("$dir/ops.json") ? [2 => ['type' => 'file', 'name' => 'ops.json', 'path' => 'deploy-queue/ops.json']] : []));
if (preg_match("#^$p/contents/(deploy-results/(manifest-\\w+|run)\\.txt)$#", $path, $m) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $f = "$dir/" . basename($m[1]); $log("put " . $m[1]);
    file_put_contents($f, base64_decode(json_decode(file_get_contents('php://input'), true)['content'])); $json(201, []);
}
if (preg_match("#^$p/contents/deploy-results/(manifest-\\w+|run)\\.txt$#", $path)) $json(404, []);
if ($path === "$p/commits") $json(200, [['sha' => 'c1']]);
if (preg_match("#^$p/contents/deploy-results/queue/(\w+)\.json$#", $path, $m)) {
    $f = "$dir/result-{$m[1]}.json";
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') { $log("put {$m[1]}"); file_put_contents($f, base64_decode(json_decode(file_get_contents('php://input'), true)['content'])); $json(201, []); }
    if ($m[1] === 'old' || is_file($f)) $json(200, ['sha' => 'r1']);
    $json(404, []);
}
if ($path === "$p/contents/deploy-queue/new.json") { readfile("$dir/new.json"); exit; }
if ($path === "$p/contents/deploy-queue/ops.json") { if (is_file("$dir/ops.json")) { readfile("$dir/ops.json"); exit; } $json(404, []); }
if ($path === "$p/contents/pwa/public_html/a.php") { echo "<?php echo 1;\n"; exit; }
$json(404, ['path' => $path]);
