<?php
// Unit tests for primary_anchor.php (holding-backup BKP-05 Step 183).
$tmp = sys_get_temp_dir() . '/ctl_anchor_test_' . bin2hex(random_bytes(4));
mkdir($tmp . '/app', 0700, true);
mkdir($tmp . '/var', 0700, true);

// Copy lib.php and primary_anchor.php into isolated temp root so CTL_ROOT points to $tmp
copy(__DIR__ . '/../files/app/lib.php', $tmp . '/app/lib.php');
copy(__DIR__ . '/../files/app/primary_anchor.php', $tmp . '/app/primary_anchor.php');
require $tmp . '/app/primary_anchor.php';

$pass = 0; $fail = 0;
function ok(bool $cond, string $msg): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $msg\n"; }
    else { $fail++; echo "FAIL $msg\n"; }
}

$t0 = 1795624000;

// 1. Initial empty state
$st0 = anchor_status('phone1');
ok($st0['ok'] === true && $st0['has_anchor'] === false && $st0['is_caller_primary'] === false, 'initial state has no anchor');
ok(anchor_require_primary('phone1') !== null, 'primary gate blocks when no anchor registered');

// 2. Bad vault_dir traversal rejected
[$cBad, $bBad] = anchor_register('phone1', ['label' => 'My Phone', 'vault_dir' => '../etc'], $t0);
ok($cBad === 400 && $bBad['ok'] === false, 'path traversal in vault_dir refused (400)');

// 3. Register phone1 as single primary anchor
[$c1, $b1] = anchor_register('phone1', ['label' => 'گوشیِ اصلی'], $t0);
ok($c1 === 200 && $b1['has_anchor'] === true && $b1['is_caller_primary'] === true, 'phone1 registered as primary anchor');
ok(anchor_require_primary('phone1') === null, 'primary gate allows phone1');
ok(anchor_require_primary('phone2') !== null, 'primary gate refuses non-primary phone2');

// 4. Idempotent label update by primary device
[$cUp, $bUp] = anchor_register('phone1', ['label' => 'تبلتِ اصلی'], $t0 + 10);
ok($cUp === 200 && $bUp['anchor']['label'] === 'تبلتِ اصلی' && $bUp['anchor']['registered_at'] === $t0, 'primary device updates label without losing registered_at');

// 5. Second device refused by anchor-register (409)
[$c2, $b2] = anchor_register('phone2', ['label' => 'گوشیِ دوم'], $t0 + 20);
ok($c2 === 409 && $b2['ok'] === false && $b2['current_device_id'] === 'phone1', 'second device refused direct register (409)');

// 6. Transfer request by already-primary device refused (409)
[$cSelf, ] = anchor_transfer_request('phone1', ['label' => 'خودم', 'reason' => 'تست'], $t0 + 30);
ok($cSelf === 409, 'primary device cannot transfer to itself (409)');

// 7. Two-step transfer: phone2 requests challenge
[$cReq, $bReq] = anchor_transfer_request('phone2', ['label' => 'گوشیِ جدید', 'reason' => 'تعویضِ سخت‌افزارِ پایه'], $t0 + 40);
ok($cReq === 200 && !empty($bReq['challenge']) && $bReq['summary']['from_device'] === 'phone1', 'phone2 gets transfer challenge');
$chId = $bReq['challenge'];

// 8. Wrong device cannot confirm phone2's challenge (410)
[$cWrong, ] = anchor_transfer_confirm('phone1', ['challenge' => $chId], $t0 + 50);
ok($cWrong === 410 && anchor_require_primary('phone1') === null, 'wrong device cannot confirm transfer (410)');

// 9. Fresh challenge expires after TTL (410)
[$cReq2, $bReq2] = anchor_transfer_request('phone2', ['label' => 'گوشیِ جدید', 'reason' => 'تعویضِ سخت‌افزارِ پایه'], $t0 + 60);
[$cExp, ] = anchor_transfer_confirm('phone2', ['challenge' => $bReq2['challenge']], $t0 + 60 + CTL_CHALLENGE_TTL + 5);
ok($cExp === 410 && anchor_require_primary('phone1') === null, 'expired transfer challenge refused (410)');

// 10. Valid confirmation switches single primary anchor atomically to phone2
[$cReq3, $bReq3] = anchor_transfer_request('phone2', ['label' => 'گوشیِ جدید', 'reason' => 'تعویضِ سخت‌افزارِ پایه'], $t0 + 100);
[$cOk, $bOk] = anchor_transfer_confirm('phone2', ['challenge' => $bReq3['challenge']], $t0 + 110);
ok($cOk === 200 && $bOk['anchor']['device_id'] === 'phone2' && $bOk['anchor']['transfers_count'] === 1, 'transfer confirmed: phone2 is now sole primary anchor');
ok(anchor_require_primary('phone2') === null, 'primary gate now allows phone2');
ok(anchor_require_primary('phone1') !== null, 'primary gate now refuses old phone1');

// Cleanup
foreach (glob($tmp . '/var/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/var');
@unlink($tmp . '/app/primary_anchor.php');
@unlink($tmp . '/app/lib.php');
@rmdir($tmp . '/app');
@rmdir($tmp);

echo "\nTOTAL: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
