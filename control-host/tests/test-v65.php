<?php
// Selftest of control-host v65 (chapter progress reports — op=chapter-report). Not deployed: outside files/.
//   php control-host/tests/test-v65.php [<Agent-Project-Backup checkout>]
require __DIR__ . '/../files/app/lib.php';
require __DIR__ . '/../files/app/reports.php';

$fail = 0; $n = 0;
function ok(bool $c, string $what): void { global $fail, $n; $n++; if (!$c) { $fail++; echo "✗ $what\n"; } }

// ---- validation ----
ok(report_code_ok('STY-01') && report_code_ok('ARN-12'), 'valid codes');
ok(!report_code_ok('sty-01') && !report_code_ok('../etc') && !report_code_ok('STY-1'), 'invalid codes rejected');
ok(report_path_ok('Memory-Workflow/projects-hub/stylist-hesabdari/chapters/STY-01/REPORT.md', 'STY-01'), 'valid path matches code');
ok(!report_path_ok('Memory-Workflow/projects-hub/stylist-hesabdari/chapters/STY-02/REPORT.md', 'STY-01'), 'mismatched code rejected');
ok(!report_path_ok('Memory-Workflow/projects-hub/../secrets.php', 'STY-01'), 'traversal path rejected');

// ---- parsing ----
$sample = <<<MD
# گزارشِ پیشرفتِ فصل — `STY-01` · دفترِ تغییرات

## گامِ ۱۰۰ — آزمونِ اجازهٔ تریگر
<!-- step-num: 100 -->
<!-- employee: gemini · date: 1405-08-29 -->
خطِ اولِ گزارشِ تست.
خطِ دومِ نتیجه.

## گامِ ۱۰۱ — ساختِ جدولِ change_log
<!-- step-num: 101 -->
_هنوز گزارشی برای این گام ثبت نشده است._
MD;

$p = report_parse('STY-01', 'Memory-Workflow/projects-hub/stylist-hesabdari/chapters/STY-01/REPORT.md', $sample, 1795070000);
ok($p['code'] === 'STY-01', 'parsed code');
ok(str_ends_with($p['github_url'], '/blob/main/Memory-Workflow/projects-hub/stylist-hesabdari/chapters/STY-01/REPORT.md'), 'github_url');
ok(count($p['entries']) === 2, 'two entries parsed');
ok($p['entries'][0]['num'] === 100 && $p['entries'][0]['employee'] === 'gemini' && $p['entries'][0]['date'] === '1405-08-29', 'entry 100 meta');
ok($p['entries'][0]['empty'] === false && str_contains($p['entries'][0]['body'], 'خطِ اولِ گزارشِ تست'), 'entry 100 body');
ok($p['entries'][1]['num'] === 101 && $p['entries'][1]['empty'] === true && $p['entries'][1]['body'] === '', 'entry 101 empty placeholder');

// ---- local checkout parity ----
$root = $argv[1] ?? '';
if ($root !== '' && is_dir($root . '/' . REPORTS_DIR)) {
    $all = reports_from_local($root);
    ok(count($all) === 12, 'local checkout has 12 chapter reports (got ' . count($all) . ')');
    ok(isset($all['STY-01']) && isset($all['STY-12']), 'STY-01 and STY-12 present');
    $s1 = $all['STY-01'];
    ok(count($s1['entries']) === 6, 'STY-01 has 6 steps');
    ok($s1['entries'][0]['num'] === 100 && $s1['entries'][0]['employee'] === 'gemini' && !$s1['entries'][0]['empty'], 'STY-01 step 100 populated by gemini');
    ok($s1['entries'][1]['num'] === 101 && $s1['entries'][1]['empty'] === true, 'STY-01 step 101 empty');
    $s2 = $all['STY-02'];
    ok(count($s2['entries']) === 5 && $s2['entries'][0]['empty'] === true, 'STY-02 has 5 empty steps');
}

echo ($fail ? "✗ $fail of $n failed\n" : "✓ all $n passed\n");
exit($fail ? 1 : 0);
