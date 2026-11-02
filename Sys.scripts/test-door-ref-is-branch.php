<?php
// test-door-ref-is-branch.php — the repo-native door must accept ONLY real branches as the auth ref.
// Flaw: repo-auth-accepts-pull-request-refs (1405-08-10). raw.githubusercontent.com serves refs/pull/N/head and fork
// commits through the base repo URL, and anyone can open a PR on a public repo — so a PR-ref must never open the door.
// Run (needs network to api.github.com):  php Sys.scripts/test-door-ref-is-branch.php   (exit 0 = all green)
// It copies each host's app/ to a temp dir so the host's var/ cache is never touched.
$root = dirname(__DIR__);
$cases = ['main' => true, 'refs/pull/1/head' => false, 'pull/1/head' => false, 'refs/heads/main' => false,
  '8db5e73' => false, str_repeat('a', 40) => false, 'no-such-branch-xyz-123' => false,
  '../main' => false, 'main/../x' => false, 'ma in' => false];
$bad = 0; $total = 0;
foreach (['studio', 'apps', 'tg'] as $h) {
  $tmp = sys_get_temp_dir() . '/door-ref-' . $h . '-' . getmypid();
  @mkdir("$tmp/app", 0700, true);
  foreach (glob("$root/$h-host/files/app/*.php") as $f) copy($f, "$tmp/app/" . basename($f));
  $code = 'require ' . var_export("$tmp/app/lib.php", true) . '; $c = ' . var_export($cases, true) . '; $b = 0;'
        . 'foreach ($c as $r => $w) { $g = ' . $h . '_ref_is_branch((string) $r); echo ($g === $w ? "  ✓ " : "  ✗ ") . "' . $h . ' $r\n"; if ($g !== $w) $b++; } exit($b);';
  passthru(PHP_BINARY . ' -r ' . escapeshellarg($code), $rc);
  $bad += $rc; $total += count($cases);
  exec('rm -rf ' . escapeshellarg($tmp));
}
echo ($total - $bad) . "/$total green\n";
exit($bad ? 1 : 0);
