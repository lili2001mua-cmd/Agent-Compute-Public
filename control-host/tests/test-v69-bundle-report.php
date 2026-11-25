<?php
// Red-first tests for v69: native bundle analysis report (Memory-Workflow/intake-pool/reports/<key>.md)
// served over POST api.php?op=bundle-report.
declare(strict_types=1);

$ok = 0; $fail = 0;
function check(bool $cond, string $label): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "✓ $label\n"; }
    else { $fail++; echo "✗ $label\n"; }
}

require_once __DIR__ . '/../files/app/reports.php';

check(function_exists('bundle_report_key_ok'), 'bundle_report_key_ok exists');
check(function_exists('bundle_report_parse'), 'bundle_report_parse exists');
check(function_exists('bundle_report_get'), 'bundle_report_get exists');

if ($fail > 0) {
    echo "RED: missing bundle report functions ($fail failed)\n";
    exit(1);
}

check(bundle_report_key_ok('b8860') === true, 'valid key b8860 accepted');
check(bundle_report_key_ok('b6943') === true, 'valid key b6943 accepted');
check(bundle_report_key_ok('../etc/passwd') === false, 'path traversal rejected');
check(bundle_report_key_ok('B8860') === false, 'uppercase B rejected');
check(bundle_report_key_ok('') === false, 'empty key rejected');

$sampleMd = <<<MD
---
key: b8860
topic: پشتیبان‌گیریِ سه‌لایه از پروژه‌های کلاد
session_url: https://claude.ai/code/session_01U9u5oS6UBiQNC1rVsDACdh
status: step3-done
origin: routine
employee: grok
model: Grok 3
date: ۱۴۰۵-۰۸-۱۸
---

## ۱ — چه مشکلی قرار است حل شود

اگر دسترسی به حساب قطع شود، پروژه‌ها از دست می‌روند.

## ۲ — این مشکل چگونه حل می‌شود

نسخهٔ محلی، فایلِ `HANDOFF` و مخزنِ خصوصیِ گیت‌هاب.
MD;

$doc = bundle_report_parse('b8860', 'Memory-Workflow/intake-pool/reports/b8860.md', $sampleMd, 1795635000);
check(($doc['key'] ?? '') === 'b8860', 'parsed key matches');
check(($doc['topic'] ?? '') === 'پشتیبان‌گیریِ سه‌لایه از پروژه‌های کلاد', 'parsed topic from frontmatter');
check(($doc['date'] ?? '') === '۱۴۰۵-۰۸-۱۸', 'parsed date from frontmatter');
check(($doc['status'] ?? '') === 'step3-done', 'parsed status from frontmatter');
check(($doc['employee'] ?? '') === 'grok', 'parsed employee from frontmatter');
check(($doc['model'] ?? '') === 'Grok 3', 'parsed model from frontmatter');
check(($doc['session_url'] ?? '') === 'https://claude.ai/code/session_01U9u5oS6UBiQNC1rVsDACdh', 'parsed session_url');
check(str_contains((string) ($doc['github_url'] ?? ''), '/Memory-Workflow/intake-pool/reports/b8860.md'), 'github_url points to report file');
check(count($doc['sections'] ?? []) === 2, 'parsed 2 markdown sections');
check(($doc['sections'][0]['title'] ?? '') === '۱ — چه مشکلی قرار است حل شود', 'first section title matches');
check(str_contains((string) ($doc['sections'][1]['body'] ?? ''), 'HANDOFF'), 'second section body preserved');

// Also check a real report from Agent-Project-Backup if present
$realPath = '/home/antigravity/lili2001mua-cmd/Agent-Project-Backup/Memory-Workflow/intake-pool/reports/b8860.md';
if (is_file($realPath)) {
    $realDoc = bundle_report_parse('b8860', 'Memory-Workflow/intake-pool/reports/b8860.md', (string) file_get_contents($realPath), 1795635000);
    check(count($realDoc['sections'] ?? []) >= 5, 'real b8860.md parses >= 5 sections');
}

echo "\nPASS: $ok / " . ($ok + $fail) . "\n";
exit($fail > 0 ? 1 : 0);
