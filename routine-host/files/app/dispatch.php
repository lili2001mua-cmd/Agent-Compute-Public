<?php
// The routine clock: due slots → one run per slot → fire a Claude account's dispatcher, or build a manual
// package. Panel notebook (private repo): Memory-Workflow/idea-orchestrations/routine-host-consolidation/.
// A 2xx from Claude only means "a session was created"; the only proof of done is the run file on main.
require_once __DIR__ . '/lib.php';

const R_SITE = 'https://routine.hohedoc.ir';

// ── country probe (account safety: never fire from an unsupported region) ─────────────────────────────
function r_probe_country(bool $force = false): string {
    $p = r_read_json('probe.json');
    if (!$force && ($p['at'] ?? 0) > time() - 86400 && !empty($p['country'])) return (string) $p['country'];
    $country = '';
    foreach (['https://ipinfo.io/country', 'https://api.country.is/'] as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'routine-probe']);
        $b = (string) curl_exec($ch);
        curl_close($ch);
        $j = json_decode($b, true);
        $c = is_array($j) ? (string) ($j['country'] ?? '') : trim($b);
        if (preg_match('/^[A-Z]{2}$/', $c)) { $country = $c; break; }
    }
    r_write_json('probe.json', ['country' => $country, 'at' => time()]);
    return $country;
}

function r_country_ok(): bool {
    $c = r_probe_country();
    return $c !== '' && !in_array($c, R_BLOCKED_COUNTRIES, true);
}

// ── manual package ───────────────────────────────────────────────────────────────────────────────────
function r_pack(array $job, string $runId, string $why): void {
    $t = bin2hex(random_bytes(32));
    $dir = R_ROOT . '/var/packs';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents($dir . '/' . hash('sha256', $t) . '.json', json_encode([
        'run_id' => $runId, 'job' => $job['id'], 'runbook' => $job['runbook'], 'why' => $why,
        'created' => time(), 'expires' => time() + R_PACK_TTL,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    r_notify("📦 روتینِ «{$job['id']}» خودکار اجرا نشد ({$why}).\n"
           . "بستهٔ دستی (۷۲ ساعت): " . R_SITE . "/p.php?t={$t}\n"
           . "متنِ صفحه را در یک سشنِ Claude که مخزن به آن وصل است بچسبان.");
    r_log("pack {$runId} why={$why}");
}

function r_pack_text(array $p): string {
    return "این یک اجرای دستی است، از بستهٔ routine.hohedoc.ir .\n"
         . "job={$p['job']} run={$p['run_id']}\n\n"
         . "مخزنِ خصوصیِ هلدینگ را از شاخهٔ main بخوان. فایلِ {$p['runbook']} را باز کن و بلوکِ بینِ "
         . "RUNBOOK:START و RUNBOOK:END را عیناً اجرا کن. شناسهٔ اجرا (run) را برای قفل و فایلِ پایان به کار ببر.";
}

function r_prune_packs(): void {
    foreach (glob(R_ROOT . '/var/packs/*.json') ?: [] as $f) {
        $p = @json_decode((string) file_get_contents($f), true);
        if (!$p || ($p['expires'] ?? 0) < time() || !empty($p['closed'])) @unlink($f);
    }
}

function r_close_packs(string $runId): void {
    foreach (glob(R_ROOT . '/var/packs/*.json') ?: [] as $f) {
        $p = @json_decode((string) file_get_contents($f), true);
        if (($p['run_id'] ?? '') === $runId) @unlink($f);
    }
}

// ── firing ───────────────────────────────────────────────────────────────────────────────────────────
function r_fire(array $key, string $jobId, string $runId): array {
    $h = ['Authorization: Bearer ' . $key['token'], 'Content-Type: application/json',
          'anthropic-version: ' . R_ANTHROPIC_VERSION];
    if ($key['beta'] !== '') $h[] = 'anthropic-beta: ' . $key['beta'];
    $ch = curl_init($key['url']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $h, CURLOPT_HEADER => true,
        CURLOPT_POSTFIELDS => json_encode(['text' => "job={$jobId} run={$runId}"])]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $retry = 0;
    if (is_string($resp) && preg_match('/^retry-after:\s*(\d+)/im', substr($resp, 0, $hs), $m)) $retry = (int) $m[1];
    $session = '';
    if (is_string($resp)) {
        $j = json_decode(substr($resp, $hs), true);
        if (is_array($j) && preg_match('#^https://claude\.ai/[A-Za-z0-9/_\-]+$#', (string) ($j['claude_code_session_url'] ?? '')))
            $session = $j['claude_code_session_url'];
    }
    return ['code' => $code, 'retry' => $retry, 'session' => $session];
}

// One attempt over the account list, by the panel's table. Returns the run's new state fields.
function r_dispatch(array $job, string $runId, array $keys, array &$health): array {
    $scope = $job['account_class'];
    $tried = 0;
    foreach ($job['accounts'] as $alias) {
        $k = $keys[$alias][$scope] ?? null;
        $hk = "$alias-$scope";
        if (!$k) continue;
        $hs = $health[$hk] ?? ['state' => 'ok'];
        if ($hs['state'] === 'dead') continue;
        if ($hs['state'] === 'cooldown' && ($hs['until'] ?? 0) > time()) continue;
        $tried++;
        $r = r_fire($k, $job['id'], $runId);
        $code = $r['code'];
        r_log("fire {$runId} via {$hk} http={$code}");
        $health[$hk] = ['state' => 'ok', 'last_code' => $code, 'at' => time()];
        if ($code >= 200 && $code < 300) {
            if ($r['session'] !== '') r_notify("▶️ روتینِ «{$job['id']}» روی {$alias} روشن شد: {$r['session']}");
            return ['state' => 'fired', 'via' => $hk, 'fired_at' => time()];
        }
        if ($code === 401 || $code === 404) {
            $health[$hk]['state'] = 'dead';
            r_notify("🔑 کلیدِ {$hk} دیگر کار نمی‌کند (HTTP {$code}). در claude.ai/code/routines توکنِ دیسپچر را دوباره بساز و فایلِ keys/{$hk}.key را عوض کن.");
            continue;
        }
        if ($code === 429) {
            // Never move to another account on a usage cap: that would be working around the cap.
            $health[$hk] = ['state' => 'cooldown', 'until' => time() + max($r['retry'], 3600), 'last_code' => 429, 'at' => time()];
            return ['state' => 'pack', 'why' => "سقفِ مصرفِ {$alias}"];
        }
        if ($code === 403) return ['state' => 'halt', 'why' => 'HTTP 403 — شاید منطقه یا سازمان بسته است'];
        if ($code === 400) return ['state' => 'pack', 'why' => 'HTTP 400 — ایرادِ خودِ درخواست'];
        // 5xx / network: cool this key for 30 minutes and try the next account.
        $health[$hk] = ['state' => 'cooldown', 'until' => time() + 1800, 'last_code' => $code, 'at' => time()];
    }
    return ['state' => 'pack', 'why' => $tried ? 'هیچ اکانتی جواب نداد' : 'هیچ کلیدِ سالمی برای این روتین نیست'];
}

// ── proof of done: the run file on the private repo's main ───────────────────────────────────────────
// Returns 'done' | 'absent' | 'unknown' (read failed — never treated as "not done").
function r_done_on_main(array $job, string $runId): string {
    $tok = r_secret('GITHUB_READ_TOKEN');
    if ($tok === '') return 'unknown';
    $path = str_replace('{run_id}', $runId, $job['done_path']);
    $ch = curl_init('https://api.github.com/repos/' . R_PRIVATE_REPO . '/contents/' . str_replace('%2F', '/', rawurlencode($path)) . '?ref=main');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'routine-host',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Accept: application/vnd.github.raw+json']]);
    $b = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 404) return 'absent';
    if ($code !== 200 || !is_string($b)) return 'unknown';
    $j = json_decode($b, true);
    return (is_array($j) && ($j['status'] ?? '') === 'done') ? 'done' : 'absent';
}

// ── the tick ─────────────────────────────────────────────────────────────────────────────────────────
function r_clock_tick(): void {
    $now = time();
    $jobs = r_jobs()['jobs'] ?? [];
    $runs = r_read_json('runs.json');
    $health = r_read_json('keys-health.json');
    $k = r_keys();
    $keys = $k['keys'];
    $live = r_armed() && r_country_ok();
    if (r_read_json('halt.json')['halted'] ?? false) $live = false;

    foreach ($jobs as $job) {
        $win = (int) ($job['start_within_min'] ?? 60);
        $slot = r_last_slot($job['cron_utc'], $now, $win);
        if (!$slot) continue;
        $runId = r_run_id($job['id'], $slot);
        if (isset($runs[$runId])) continue;                        // one run per slot, ever
        if (($job['mode'] ?? 'shadow') === 'shadow') {
            $runs[$runId] = ['job' => $job['id'], 'slot' => $slot, 'state' => 'shadow'];
            continue;                                              // recorded only: the old trigger still runs it
        }
        if (!$live || !($job['needs_claude'] ?? true)) {
            $why = !r_armed() ? 'شلیک هنوز روشن نشده' : (!r_country_ok() ? 'کشورِ هاست تأیید نشد' : 'شلیک متوقف است');
            r_pack($job, $runId, $why);
            $runs[$runId] = ['job' => $job['id'], 'slot' => $slot, 'state' => 'pack'];
            continue;
        }
        $res = r_dispatch($job, $runId, $keys, $health);
        if ($res['state'] === 'halt') {
            r_write_json('halt.json', ['halted' => true, 'at' => $now, 'why' => $res['why']]);
            r_notify("⛔ هاستِ روتین همهٔ شلیک‌ها را متوقف کرد: {$res['why']}. تا وقتی halt.json پاک نشود فقط بستهٔ دستی می‌سازد.");
            $res = ['state' => 'pack', 'why' => $res['why']];
        }
        if ($res['state'] === 'pack') r_pack($job, $runId, $res['why']);
        $runs[$runId] = array_merge(['job' => $job['id'], 'slot' => $slot], $res);
    }

    // Watch fired and packed runs for the done file (at most every 15 minutes per run).
    $byId = [];
    foreach ($jobs as $j) $byId[$j['id']] = $j;
    foreach ($runs as $runId => &$r) {
        if (!in_array($r['state'], ['fired', 'pack', 'unknown'], true)) continue;
        $job = $byId[$r['job']] ?? null;
        if (!$job || empty($job['done_path'])) continue;
        if (($r['checked'] ?? 0) > $now - 900) continue;
        $r['checked'] = $now;
        $orig = $r['was'] ?? $r['state'];                          // 'unknown' remembers what it interrupted
        $d = r_done_on_main($job, $runId);
        if ($d !== 'unknown') { $r['state'] = $orig; unset($r['was']); }
        if ($d === 'done') {
            $manual = $orig === 'pack';
            $r['state'] = $manual ? 'done_manual' : 'done';
            r_close_packs($runId);
            if ($manual) r_notify("✅ اجرای دستیِ «{$r['job']}» انجام شد ({$runId}).");
            continue;
        }
        if ($d === 'unknown') {
            if ($r['state'] !== 'unknown') r_notify("❔ هاستِ روتین نتوانست فایلِ پایانِ {$runId} را از گیت‌هاب بخواند (توکن یا شبکه). بسته‌ای ساخته نشد.");
            $r['was'] = $orig;
            $r['state'] = 'unknown';
            continue;
        }
        $fin = (int) ($job['finish_within_min'] ?? 180) * 60;
        if ($r['state'] === 'fired' && $now - (int) ($r['fired_at'] ?? $r['slot']) > $fin) {
            r_pack($job, $runId, 'شروع شد ولی در مهلت تمام نشد — ازسرگیری');
            $r['state'] = 'pack';
        } elseif ($r['state'] === 'pack' && $now - (int) $r['slot'] > R_PACK_TTL) {
            $r['state'] = 'missed';
            r_notify("⚠️ روتینِ «{$r['job']}» در این نوبت اجرا نشد و بسته‌اش هم مصرف نشد ({$runId}).");
        }
    }
    unset($r);

    // Keep 30 days of runs.
    foreach ($runs as $id => $r) if (($r['slot'] ?? 0) < $now - 30 * 86400) unset($runs[$id]);
    r_write_json('runs.json', $runs);
    r_write_json('keys-health.json', $health);
    r_prune_packs();
}
