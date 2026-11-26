<?php
declare(strict_types=1);

require_once __DIR__ . '/../files/app/hub_gate.php';

$pass = 0; $fail = 0;
function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $label\n"; }
    else { $fail++; echo "FAIL $label\n"; }
}

$secret = str_repeat('a', 64);
$now = 1795660000;

$m = hub_gate_mint_ticket($secret, 'phone1', 'primary', 120, $now);
ok(str_starts_with($m['ticket'], 'hg1.'), 'ticket starts with hg1.');
ok($m['expires_in'] === 120, 'expires_in is 120s');

$v = hub_gate_verify_ticket($secret, $m['ticket'], $now + 30);
ok($v['ok'] === true && $v['role'] === 'primary' && $v['device'] === 'phone1', 'valid ticket verifies');

$vExp = hub_gate_verify_ticket($secret, $m['ticket'], $now + 121);
ok($vExp['ok'] === false && $vExp['error'] === 'expired', 'expired ticket rejected');

$tampered = substr($m['ticket'], 0, -4) . '0000';
$vTamper = hub_gate_verify_ticket($secret, $tampered, $now + 10);
ok($vTamper['ok'] === false && $vTamper['error'] === 'bad_sig', 'tampered ticket rejected');

$state = ['hosts' => ['primary_hub' => 'https://hub.primary.test', 'standby_hub' => 'https://hub.standby.test']];

$rAuto1 = hub_gate_issue(['target' => 'auto'], 'phone1', $state, fn(string $u) => str_contains($u, 'primary'), $secret);
ok($rAuto1['code'] === 200 && $rAuto1['body']['role'] === 'primary' && $rAuto1['body']['url'] === 'https://hub.primary.test', 'auto selects live primary');

$rAuto2 = hub_gate_issue(['target' => 'auto'], 'phone1', $state, fn(string $u) => str_contains($u, 'standby'), $secret);
ok($rAuto2['code'] === 200 && $rAuto2['body']['role'] === 'standby' && $rAuto2['body']['url'] === 'https://hub.standby.test', 'auto fails over to live standby when primary is down');

$rStd = hub_gate_issue(['target' => 'standby'], 'phone1', $state, fn(string $u) => true, $secret);
ok($rStd['code'] === 200 && $rStd['body']['role'] === 'standby', 'explicit standby works');

$rBad = hub_gate_issue(['target' => 'invalid'], 'phone1', $state, fn(string $u) => true, $secret);
ok($rBad['code'] === 400, 'invalid target rejected');

echo "\nTOTAL: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
