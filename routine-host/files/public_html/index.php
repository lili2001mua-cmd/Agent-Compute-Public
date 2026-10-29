<?php
// Human status page (Persian). Same data as status.php, never a secret.
require_once __DIR__ . '/../app/lib.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$k = r_keys();
$runs = r_read_json('runs.json');
uasort($runs, fn($a, $b) => ($b['slot'] ?? 0) <=> ($a['slot'] ?? 0));
$lastTick = (int) @file_get_contents(r_var('last-tick.txt'));
$probe = r_read_json('probe.json');
$names = ['shadow' => 'فقط ثبت (روتینِ قدیم هنوز اجرا می‌کند)', 'fired' => 'روشن شد، منتظرِ پایان', 'done' => 'انجام شد',
          'pack' => 'بستهٔ دستی فرستاده شد', 'done_manual' => 'دستی انجام شد', 'missed' => 'از دست رفت', 'unknown' => 'نامعلوم (خطای خواندن)'];
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ساعتِ روتین‌ها</title>
<style>
:root{--bg:#fafaf7;--fg:#1d1d1b;--muted:#6b6b66;--card:#fff;--line:#e4e2dc;--ok:#2f6f4f;--bad:#a23b2a}
@media (prefers-color-scheme:dark){:root{--bg:#161614;--fg:#ecebe6;--muted:#a19f98;--card:#1f1f1c;--line:#33322e;--ok:#7cc39c;--bad:#e58b7a}}
body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.8 Vazirmatn,Tahoma,sans-serif}
main{max-width:860px;margin:0 auto;padding:24px 16px}
table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden}
td,th{padding:8px 10px;border-bottom:1px solid var(--line);text-align:right;vertical-align:top}
.wrap{overflow-x:auto}.ok{color:var(--ok)}.bad{color:var(--bad)}small{color:var(--muted)}
</style></head><body><main>
<h1>ساعتِ روتین‌ها</h1>
<p>آخرین تیک: <?= $lastTick ? $e(round((time() - $lastTick) / 60)) . ' دقیقه پیش' : '<span class="bad">هنوز اجرا نشده</span>' ?>
 · شلیک: <?= r_armed() ? '<span class="ok">روشن</span>' : 'خاموش (فقط بستهٔ دستی)' ?>
 · کشورِ هاست: <?= $e($probe['country'] ?? 'هنوز سنجیده نشده') ?></p>
<h2>کلیدها</h2>
<p><?php foreach ($k['keys'] as $a => $s) foreach ($s as $sc => $_) echo '<span class="ok">' . $e("$a-$sc") . '</span> '; ?>
<?= $k['keys'] ? '' : 'هنوز کلیدی گذاشته نشده.' ?></p>
<?php foreach ($k['problems'] as $pr) echo '<p class="bad">⚠️ ' . $e($pr) . '</p>'; ?>
<h2>روتین‌ها</h2>
<div class="wrap"><table><tr><th>روتین</th><th>اکانت</th><th>نوبتِ بعد (تهران)</th><th>حالت</th></tr>
<?php foreach ((r_jobs()['jobs'] ?? []) as $j): $n = r_next_slot($j['cron_utc'], time()); ?>
<tr><td><?= $e($j['id']) ?></td><td><?= $e(implode('، ', $j['accounts'])) ?> · <?= $e($j['account_class']) ?></td>
<td><?= $n ? $e(r_tehran($n)) : '—' ?></td><td><?= ($j['mode'] ?? 'shadow') === 'live' ? 'زنده' : 'سایه' ?></td></tr>
<?php endforeach; ?></table></div>
<h2>اجراهای اخیر</h2>
<div class="wrap"><table><tr><th>اجرا</th><th>وضعیت</th></tr>
<?php foreach (array_slice($runs, 0, 20, true) as $id => $r): ?>
<tr><td><?= $e($id) ?></td><td><?= $e($names[$r['state']] ?? $r['state']) ?></td></tr>
<?php endforeach; ?></table></div>
<p><small>دادهٔ ماشین‌خوان: <a href="status.php">status.php</a></small></p>
</main></body></html>
