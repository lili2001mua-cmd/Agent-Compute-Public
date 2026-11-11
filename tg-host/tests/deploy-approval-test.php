<?php
// Offline test of the deploy-approval lock (phase9): only the owner's tap with the current nonce, before expiry, writes deploy-ref.txt.
$base = sys_get_temp_dir() . '/tgdeploy' . getmypid(); @mkdir($base . '/var', 0700, true);
define('TG_BASE', $base);
function tg_var($r = '') { return TG_BASE . '/var/' . $r; }
function tg_read_json($p) { return is_file($p) ? json_decode(file_get_contents($p), true) : null; }
function tg_write_json($p, $d) { file_put_contents($p, json_encode($d)); }
function tg_log($a, $b) {}
function tg_bot_call($m, $p) { return ['ok' => true]; }
require __DIR__ . '/../files/app/jobs/phase9.php';
$fail = 0; $ok = function ($c, $l) use (&$fail) { echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n"; if (!$c) $fail++; };
$OWNER = 111; $SHA = str_repeat('a', 40); $OLD = str_repeat('b', 40);
file_put_contents(TG_BASE . '/deploy-ref.txt', $OLD . "\n");
tg_write_json(tg_var('chain-owner.json'), ['id' => $OWNER]);
$nonce = '0123456789abcdef';
$pend = fn($exp) => tg_write_json(tg_var('deploy-pending.json'), ['sha' => $SHA, 'nonce' => $nonce, 'expires' => $exp]);
$pend(time() + 100);
$ok(tg_deploy_on_callback(222, "deploy:$nonce:ok") === '' && trim(file_get_contents(TG_BASE . '/deploy-ref.txt')) === $OLD, 'a stranger cannot approve');
$ok(tg_deploy_on_callback($OWNER, 'deploy:ffffffffffffffff:ok') !== '' && trim(file_get_contents(TG_BASE . '/deploy-ref.txt')) === $OLD, 'a stale nonce cannot approve');
$ok(tg_deploy_on_callback($OWNER, "deploy:$nonce:no") !== '' && trim(file_get_contents(TG_BASE . '/deploy-ref.txt')) === $OLD, 'refusal does not deploy');
$ok(tg_deploy_on_callback($OWNER, "deploy:$nonce:ok") !== '' && trim(file_get_contents(TG_BASE . '/deploy-ref.txt')) === $OLD, 'after refusal the nonce is dead');
$pend(time() - 1);
$ok(tg_deploy_on_callback($OWNER, "deploy:$nonce:ok") !== '' && trim(file_get_contents(TG_BASE . '/deploy-ref.txt')) === $OLD, 'expired proposal cannot deploy');
$pend(time() + 100);
$ok(str_starts_with(tg_deploy_on_callback($OWNER, "deploy:$nonce:ok"), '✅') && trim(file_get_contents(TG_BASE . '/deploy-ref.txt')) === $SHA, 'owner tap with the live nonce deploys exactly that SHA');
$ok(tg_deploy_on_callback($OWNER, "deploy:$nonce:ok") !== '' && (tg_read_json(tg_var('deploy-pending.json'))['approved_sha'] ?? '') === $SHA, 'the nonce is single-use');
$ok(tg_deploy_on_callback($OWNER, 'deploy:x;rm:ok') === '', 'malformed data ignored');
echo "\n# deploy approval tests: $fail failures\n"; exit($fail ? 1 : 0);
