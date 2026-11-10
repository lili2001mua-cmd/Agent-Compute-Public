<?php
// وضعیتِ زندهٔ دکمهٔ «بستهٔ تازه» (۱۴۰۵-۰۸-۱۹ · خواستِ کاربر: «دکمه در حالِ انتظار بماند تا بسته واقعاً گامِ ۳ را طی کند و
// منتقل شود»). یک رکورد برای آخرین فشار در var/button-state.json؛ سه نویسنده:
//   ۱) api.php (op=session-command ، t-intake)  → queued  (فشارِ تازه، یا 409 اگر قبلی هنوز در راه است)
//   ۲) runner.php?op=progress (امضای OIDC ِ button-chain.yml) → step1 · step2 · step3 · delivering · delivered · failed
//   ۳) btn_tick() (کرونِ ۵ دقیقه‌ای) → نگهبان: گزارشِ گامِ ۳ روی main ، تلاشِ دوبارهٔ یک‌بارهٔ گامِ ۳ ، مهلتِ هر مرحله
// پایان فقط دو حالت است: delivered (بسته در «Routine Trainer 1 & 2» با پیوند) یا failed (با دلیل و مرحله).
// سند: Agent-Project-Backup/Memory-Workflow/idea-orchestrations/button-chain-wait/PANEL.md
require_once __DIR__ . '/lib.php';

const BTN_STAGES = ['queued', 'step1', 'step2', 'step3', 'delivering', 'delivered', 'failed'];
const BTN_LIMIT_MIN = ['queued' => 15, 'step1' => 60, 'step2' => 120, 'step3' => 90, 'delivering' => 45];
const BTN_TOTAL_MIN = 330;   // سقفِ کلِ یک فشار (۵٫۵ ساعت) — زیرِ قفلِ ۶ ساعتهٔ اپ و فشارِ دوباره
const BTN_RETRY_STEP3 = 2;   // دو شلیکِ دوباره: سشنِ اول ممکن است فقط گامِ ۲ را تمام کند (partial)، دومی فقط گامِ ۳ ؛ بعد «ناموفق»

function btn_read(): array { return ctl_json_read('button-state.json', []) ?: []; }
function btn_write(array $s): void { ctl_json_write('button-state.json', $s); }

/** خواندن-تغییر-نوشتنِ اتمی زیرِ قفل (فشارِ هم‌زمان، رانر و کرون هم‌دیگر را پاک نکنند). $fn: state → state|null(بی‌تغییر). */
function btn_update(callable $fn) {
    $lk = fopen(ctl_var('button.lock'), 'c');
    if ($lk) flock($lk, LOCK_EX);
    try { $s = btn_read(); $n = $fn($s); if (is_array($n)) btn_write($n); return is_array($n) ? $n : $s; }
    finally { if ($lk) { flock($lk, LOCK_UN); fclose($lk); } }
}
function btn_active(array $s): bool { return in_array($s['stage'] ?? '', ['queued', 'step1', 'step2', 'step3', 'delivering'], true); }

/** یک گذار؛ مرحله هرگز عقب نمی‌رود (پیامِ دیررسِ رانر حالتِ جلوتر را خراب نمی‌کند). */
function btn_set(array $s, string $stage, array $extra = []): array {
    $cur = array_search($s['stage'] ?? 'queued', BTN_STAGES, true);
    $new = array_search($stage, BTN_STAGES, true);
    if ($new === false) return $s;
    if (!in_array($s['stage'] ?? '', ['delivered', 'failed'], true) && ($stage === 'failed' || $new >= $cur)) {
        if (($s['stage'] ?? '') !== $stage) { if ($stage === 'failed') $s['stage_before_fail'] = $s['stage'] ?? ''; $s['stage'] = $stage; $s['stage_at'] = time(); }
        $s['history'][] = ['stage' => $stage, 'at' => time()] + array_intersect_key($extra, ['note' => 1]);
        $s['history'] = array_slice($s['history'], -20);
    }
    foreach (['key', 'link', 'error', 'run'] as $f) if (isset($extra[$f]) && $extra[$f] !== '') $s[$f] = $extra[$f];
    if ($stage === 'failed' && empty($s['failed_stage'])) $s['failed_stage'] = (string) ($extra['at_stage'] ?? ($s['stage_before_fail'] ?? ''));
    if ($stage === 'delivered' || $stage === 'failed') $s['done_at'] = $s['done_at'] ?? time();
    return $s;
}

/** فشارِ تازه: [ok, state]. فشارِ دوم وقتی قبلی هنوز در راه است رد می‌شود (ضدِ دوباره‌کشیدن). */
function btn_press(): array {
    $fresh = false;
    $s = btn_update(function (array $s) use (&$fresh) {
        if (btn_active($s) && time() - (int) ($s['pressed_at'] ?? 0) < 6 * 3600) return null;
        $fresh = true;
        return ['id' => gmdate('YmdHis'), 'pressed_at' => time(), 'stage' => 'queued', 'stage_at' => time(), 'retries' => 0,
                'history' => [['stage' => 'queued', 'at' => time()]]];
    });
    return [$fresh, $s];
}

/** آنچه اپ می‌بیند (op=trainer-board → button). */
function btn_public(): array {
    $s = btn_read();
    if (!$s) return ['stage' => ''];
    // ⛔ «رسید» کارِ تمام‌شده است: ده دقیقه بعد کارت بسته می‌شود (خواستِ کاربر ۱۴۰۵-۰۸-۱۹ «باید بسته شده باشد و نشان داده نشود»)
    if (($s['stage'] ?? '') === 'delivered' && time() - (int) ($s['done_at'] ?? 0) > 600) return ['stage' => ''];
    $out = array_intersect_key($s, array_flip(['id', 'pressed_at', 'stage', 'stage_at', 'key', 'link', 'error', 'retries', 'done_at', 'failed_stage']));
    $out['active'] = btn_active($s);
    return $out;
}

/** پیوندِ آرشیوِ بسته از main ، برای هر منبع (telegram یا drive) — یک جا برای همهٔ بررسی‌های «رسید». */
function btn_archive_link(string $key): string {
    foreach (['telegram', 'drive'] as $src) {
        $st = json_decode((string) gh_raw("Memory-Workflow/intake-pool/state/$src/$key/status.json"), true);
        if (is_array($st)) return (string) (($st['archive'] ?? [])['link'] ?? '');
    }
    return '';
}

function btn_tehran_stamp(int $t): string { return (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('YmdHi'); }

/** شکستِ کرون: اجرای گیت‌هابِ همان فشار هم لغو می‌شود تا فشارِ بعدی پشتِ آن در صف نماند. */
function btn_fail(array $obs, string $why, string $at): void {
    $n = btn_update(function (array $s) use ($obs, $why, $at) {
        if (($s['id'] ?? '') !== ($obs['id'] ?? '') || ($s['stage'] ?? '') !== ($obs['stage'] ?? '')) return null;   // کسی جلوتر برده
        return btn_set($s, 'failed', ['error' => $why, 'note' => $at, 'at_stage' => $at]);
    });
    if (($n['stage'] ?? '') === 'failed' && !empty($n['run'])) gh('POST', '/repos/' . CTL_REPO . '/actions/runs/' . $n['run'] . '/cancel');
}

/** نگهبانِ کرون. خواندن فقط از main ِ مخزنِ خصوصی و fire-state ِ هاستِ روتین؛ نوشتن فقط زیرِ قفل و فقط اگر رکورد عوض نشده. */
function btn_tick(): void {
    require_once __DIR__ . '/board.php';   // gh_raw
    $s = btn_read();
    // ⛔ «نشد»ی که بسته‌اش بعداً (با ترمیم یا شلیکِ دستی) به کانال رسید، «رسید» می‌شود؛ وگرنه کارتِ قرمز دروغ می‌گوید (b8879)
    if (($s['stage'] ?? '') === 'failed' && ($s['key'] ?? '') !== '' && time() - (int) ($s['done_at'] ?? 0) < 24 * 3600) {
        $link = btn_archive_link((string) $s['key']);
        if ($link !== '') btn_update(function (array $x) use ($s, $link) {
            if (($x['id'] ?? '') !== ($s['id'] ?? '') || ($x['stage'] ?? '') !== 'failed') return null;
            $x['stage'] = 'delivered'; $x['link'] = $link; $x['done_at'] = time(); unset($x['error'], $x['failed_stage']);
            $x['history'][] = ['stage' => 'delivered', 'at' => time(), 'note' => 'پس از ترمیم به کانال رسید'];
            return $x;
        });
        return;
    }
    if (!btn_active($s)) return;
    $now = time(); $stage = $s['stage']; $since = (int) ($s['stage_at'] ?? $now); $key = (string) ($s['key'] ?? '');
    $same = fn(array $x) => ($x['id'] ?? '') === ($s['id'] ?? '') && ($x['stage'] ?? '') === $stage && (int) ($x['stage_at'] ?? 0) === $since;
    if ($now - (int) ($s['pressed_at'] ?? $now) > 60 * BTN_TOTAL_MIN) { btn_fail($s, 'زمانِ کلِ این بسته گذشت', $stage); return; }
    // هاستِ روتین شلیک را رد کرد (مسلح نیست، کشورِ نامعتبر، کلیدِ ناسالم) یا شلیک کرد ولی گزارشِ step3 ِ رانر نرسید
    if ($key !== '' && in_array($stage, ['step2', 'step3'], true)) {
        $fs = (ctl_json_read('fire-state.json', []) ?: [])[$key] ?? null;
        if (is_array($fs) && (int) ($fs['at'] ?? 0) >= $since) {
            if (($fs['state'] ?? '') === 'failed') { btn_fail($s, 'هاستِ روتین گامِ ۳ را شلیک نکرد: ' . (string) ($fs['error'] ?? ''), 'step3'); return; }
            if (($fs['state'] ?? '') === 'fired' && $stage === 'step2') {
                btn_update(fn(array $x) => $same($x) ? btn_set($x, 'step3', ['note' => 'سشنِ گامِ ۳ ساخته شد']) : null); return;
            }
        }
    }
    if ($stage === 'step3' && $key !== '') {
        $rep = gh_raw("Memory-Workflow/intake-pool/reports/$key.md");
        if ($rep !== null && preg_match('/^status:\s*step3-done\b/m', $rep)) {
            btn_update(fn(array $x) => $same($x) ? btn_set($x, 'delivering', ['note' => 'گزارشِ گامِ ۳ روی main']) : null); return;
        }
        if ($rep !== null && preg_match('/^status:\s*(blocked-step2|nothing-to-do)\b/m', $rep, $m)) {
            btn_fail($s, $m[1] === 'blocked-step2' ? 'گامِ ۳ ایستاد: گامِ ۲ ناقص بود' : 'گامِ ۳ چیزی برای انجام پیدا نکرد', 'step3'); return;
        }
        // فقط نشانهٔ واقعیِ شکست (فایلِ failed ِ دیسپچر پس از درخواست) تلاشِ دوباره می‌گیرد؛ دیرکردِ تنها هرگز دو سشنِ هم‌زمان نمی‌سازد
        [$c, $ls] = gh('GET', '/repos/' . CTL_REPO . '/contents/Memory-Workflow/routine-host/runs?ref=' . CTL_BRANCH);
        $failed = false; $mark = btn_tehran_stamp($since); $fname = '';
        foreach (($c === 200 ? $ls : []) as $f) {
            if (preg_match('/^step3-' . preg_quote($key, '/') . '-([0-9]{12})\.failed\.json$/', (string) ($f['name'] ?? ''), $m) && $m[1] >= $mark) { $failed = true; $fname = $f['name']; }
            // ⛔ اجرای «ناقص» (done با status=partial ، گزارشِ گامِ ۳ هنوز نیست) = سشن فقط بخشی را تمام کرد (معمولاً نیمهٔ سشنیِ گامِ ۲)؛
            //    شلیکِ بعدی فقط گامِ ۳ را دارد (ایجنتِ تصمیم‌گیر ۱۴۰۵-۰۸-۱۹ · ردیف: step3-session-quit-saying-it-did-not-fit · b8879)
            elseif (preg_match('/^step3-' . preg_quote($key, '/') . '-([0-9]{12})\.done\.json$/', (string) ($f['name'] ?? ''), $m) && $m[1] >= $mark
                    && ((json_decode((string) gh_raw('Memory-Workflow/routine-host/runs/' . $f['name']), true) ?: [])['status'] ?? '') === 'partial') { $failed = true; $fname = $f['name']; }
        }
        // ⛔ شکستِ محیطی (دسترسیِ بسته، 403 ، آرتیفکتِ نبود) با تلاشِ دوباره درست نمی‌شود — همان‌جا با دلیل بایست، سشنِ دوم نسوزان
        //    (ردیف: photo-step3-needs-drive-but-session-is-github-only · b8879 دو بار با studio 403 افتاد)
        if ($failed) {
            $why = (string) ((json_decode((string) gh_raw('Memory-Workflow/routine-host/runs/' . $fname), true) ?: [])['reason'] ?? '');
            if (preg_match('/unreachable|403|denied|blocked|artifact|not-found/i', $why)) { btn_fail($s, 'گامِ ۳ به منبعِ لازم نرسید: ' . $why, 'step3'); return; }
        }
        if ($failed) {
            if ((int) ($s['retries'] ?? 0) < BTN_RETRY_STEP3) {
                $n = btn_update(function (array $x) use ($same, $now, $key) {
                    if (!$same($x)) return null;
                    $dir = ctl_var('fire-req'); if (!is_dir($dir)) mkdir($dir, 0700, true);
                    file_put_contents($dir . '/' . $now . '-' . $key . '.json', json_encode(['key' => $key, 'at' => $now, 'device' => 'button-watch']), LOCK_EX);
                    $x['retries'] = (int) ($x['retries'] ?? 0) + 1;
                    $x = btn_set($x, 'step3', ['note' => 'سشنِ گامِ ۳ ناتمام یا نیمه‌کاره ماند؛ شلیکِ بعدی فقط گامِ ۳']);
                    $x['stage_at'] = $now;
                    return $x;
                });
                if (($n['retries'] ?? 0) > ($s['retries'] ?? 0)) ctl_log("button step3 retry $key");
                return;
            }
            btn_fail($s, 'سشنِ گامِ ۳ پس از سه شلیک هم گزارش نداد', 'step3'); return;
        }
    }
    if ($stage === 'delivering' && $key !== '') {
        $link = btn_archive_link($key);
        if ($link !== '') { btn_update(fn(array $x) => $same($x) ? btn_set($x, 'delivered', ['link' => $link, 'note' => 'در کانالِ Routine Trainer 1 & 2']) : null); return; }
    }
    if ($now - $since > 60 * (BTN_LIMIT_MIN[$stage] ?? 120)) {
        $why = ['queued' => 'زنجیره روی گیت‌هاب شروع نشد', 'step1' => 'گامِ ۱ در مهلت تمام نشد', 'step2' => 'گامِ ۲ در مهلت تمام نشد',
                'step3' => 'گامِ ۳ در مهلت تمام نشد', 'delivering' => 'انتقال به کانالِ Routine Trainer 1 & 2 در مهلت نرسید'][$stage] ?? 'مهلت گذشت';
        btn_fail($s, $why, $stage);
    }
}
