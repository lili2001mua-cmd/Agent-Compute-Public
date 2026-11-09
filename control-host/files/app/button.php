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
const BTN_RETRY_STEP3 = 1;   // یک تلاشِ دوبارهٔ خودکار برای گامِ ۳ (سشنِ ناتمام یا دیرکرد)، بعد «ناموفق»

function btn_read(): array { return ctl_json_read('button-state.json', []) ?: []; }
function btn_write(array $s): void { ctl_json_write('button-state.json', $s); }
function btn_active(array $s): bool { return in_array($s['stage'] ?? '', ['queued', 'step1', 'step2', 'step3', 'delivering'], true); }

/** یک گذار؛ مرحله هرگز عقب نمی‌رود (پیامِ دیررسِ رانر حالتِ جلوتر را خراب نمی‌کند). */
function btn_set(array $s, string $stage, array $extra = []): array {
    $cur = array_search($s['stage'] ?? 'queued', BTN_STAGES, true);
    $new = array_search($stage, BTN_STAGES, true);
    if ($new === false) return $s;
    if (!in_array($s['stage'] ?? '', ['delivered', 'failed'], true) && ($stage === 'failed' || $new >= $cur)) {
        if (($s['stage'] ?? '') !== $stage) { $s['stage'] = $stage; $s['stage_at'] = time(); }
        $s['history'][] = ['stage' => $stage, 'at' => time()] + array_intersect_key($extra, ['note' => 1]);
        $s['history'] = array_slice($s['history'], -20);
    }
    foreach (['key', 'link', 'error', 'run'] as $f) if (isset($extra[$f]) && $extra[$f] !== '') $s[$f] = $extra[$f];
    if ($stage === 'delivered' || $stage === 'failed') $s['done_at'] = $s['done_at'] ?? time();
    return $s;
}

/** فشارِ تازه: [ok, state]. فشارِ دوم وقتی قبلی هنوز در راه است رد می‌شود (ضدِ دوباره‌کشیدن). */
function btn_press(): array {
    $s = btn_read();
    if (btn_active($s) && time() - (int) ($s['pressed_at'] ?? 0) < 6 * 3600) return [false, $s];
    $s = ['id' => gmdate('YmdHis'), 'pressed_at' => time(), 'stage' => 'queued', 'stage_at' => time(), 'retries' => 0,
          'history' => [['stage' => 'queued', 'at' => time()]]];
    btn_write($s);
    return [true, $s];
}

/** آنچه اپ می‌بیند (op=trainer-board → button). */
function btn_public(): array {
    $s = btn_read();
    if (!$s) return ['stage' => ''];
    $out = array_intersect_key($s, array_flip(['id', 'pressed_at', 'stage', 'stage_at', 'key', 'link', 'error', 'retries', 'done_at']));
    $out['active'] = btn_active($s);
    return $out;
}

function btn_tehran_stamp(int $t): string { return (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('YmdHi'); }

/** نگهبانِ کرون. خواندن فقط از main ِ مخزنِ خصوصی (gh_raw) — هیچ چیز روی گیت‌هاب نوشته نمی‌شود. */
function btn_tick(): void {
    require_once __DIR__ . '/board.php';   // gh_raw
    $s = btn_read();
    if (!btn_active($s)) return;
    $now = time(); $stage = $s['stage']; $since = (int) ($s['stage_at'] ?? $now); $key = (string) ($s['key'] ?? '');
    $late = $now - $since > 60 * (BTN_LIMIT_MIN[$stage] ?? 120);
    if ($stage === 'step3' && $key !== '') {
        $rep = gh_raw("Memory-Workflow/intake-pool/reports/$key.md");
        if ($rep !== null && preg_match('/^status:\s*step3-done\b/m', $rep)) { btn_write(btn_set($s, 'delivering', ['note' => 'گزارشِ گامِ ۳ روی main'])); return; }
        // سشنِ گامِ ۳ ِ ناتمام (فایلِ failed ِ دیسپچر پس از درخواست) یا دیرکرد ⇒ یک تلاشِ دوباره، بعد ناموفق
        [$c, $ls] = gh('GET', '/repos/' . CTL_REPO . '/contents/Memory-Workflow/routine-host/runs?ref=' . CTL_BRANCH);
        $failed = false; $mark = btn_tehran_stamp($since);
        foreach (($c === 200 ? $ls : []) as $f) {
            if (preg_match('/^step3-' . preg_quote($key, '/') . '-([0-9]{12})\.failed\.json$/', (string) ($f['name'] ?? ''), $m) && $m[1] >= $mark) $failed = true;
        }
        if ($failed || $late) {
            if ((int) ($s['retries'] ?? 0) < BTN_RETRY_STEP3) {
                $dir = ctl_var('fire-req'); if (!is_dir($dir)) mkdir($dir, 0700, true);
                file_put_contents($dir . '/' . $now . '-' . $key . '.json', json_encode(['key' => $key, 'at' => $now, 'device' => 'button-watch']), LOCK_EX);
                $s['retries'] = (int) ($s['retries'] ?? 0) + 1;
                $s = btn_set($s, 'step3', ['note' => $failed ? 'سشنِ گامِ ۳ ناتمام ماند؛ دوباره درخواست شد' : 'گامِ ۳ دیر کرد؛ دوباره درخواست شد']);
                $s['stage_at'] = $now;
                ctl_log("button step3 retry $key (" . ($failed ? 'failed run' : 'late') . ')');
                btn_write($s); return;
            }
            btn_write(btn_set($s, 'failed', ['error' => $failed ? 'سشنِ گامِ ۳ دو بار ناتمام ماند' : 'گامِ ۳ پس از تلاشِ دوباره هم در مهلت تمام نشد', 'note' => 'step3']));
            return;
        }
        return;
    }
    if ($stage === 'delivering' && $key !== '') {
        $st = json_decode((string) gh_raw("Memory-Workflow/intake-pool/state/telegram/$key/status.json"), true);
        $link = is_array($st) ? (string) (($st['archive'] ?? [])['link'] ?? '') : '';
        if ($link !== '') { btn_write(btn_set($s, 'delivered', ['link' => $link, 'note' => 'در کانالِ Routine Trainer 1 & 2'])); return; }
    }
    if ($late) {
        $why = ['queued' => 'زنجیره روی گیت‌هاب شروع نشد', 'step1' => 'گامِ ۱ در مهلت تمام نشد', 'step2' => 'گامِ ۲ در مهلت تمام نشد',
                'delivering' => 'انتقال به کانالِ Routine Trainer 1 & 2 در مهلت نرسید'][$stage] ?? 'مهلت گذشت';
        btn_write(btn_set($s, 'failed', ['error' => $why, 'note' => $stage]));
    }
}
