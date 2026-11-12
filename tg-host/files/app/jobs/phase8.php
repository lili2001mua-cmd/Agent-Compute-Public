<?php
// Phase 8 (B1) — the tg host plans the night on its own (Agent-Project-Backup notebook
// Memory-Workflow/nightly-chain-independence/public-runner-notebook-1405-08-20.md, phase B, `dry` step).
// At the night slot (03:07–03:30 Tehran) the tick (CLI, no library) enqueues the internal job `host_plan`; the job reads
// the source chat's history, groups it with the line-for-line port of tg_grouping.py (phase3.php), keeps the bundles of
// the night window and writes Memory-Workflow/nightly-pool/host/<date>.json on main (phase7.php allowlist).
// Parity contract — the same key list the Python plan step produces for the same window:
//   window   = [03:00 Tehran of date-1, 03:00 Tehran of date)              nightly_pool.py window()/target_date()
//   read     = newest → back to (since - 1 h), stop at the first older msg  telegram_pool.py run(), `floor`
//   grouping = tg_group_bundles (port of tg_grouping.group_bundles)         telegram_pool.py group_bundles()
//   in       = min(media dates) in [since, until) and ≥1 video/photo/doc    telegram_pool.py in_window() + window_keys
//   key      = "b" + min(media ids)                                          telegram_pool.py bundle_key()
//   minus    = keys whose Memory-Workflow/nightly-pool/archive/<k>.json is on main (nightly-pool.yml, plan step)
// ⛔ The plan itself is always read-only: no download, no Drive, no Telegram write, no lease (read-only history, like
//    job_batches). It also keeps the night's bundles locally (var/host-plan.json) for the B2 pull (phase10.php), which
//    runs only in mode `live`. Switch {"mode":"off|dry|live","limit":N,"plan_date":"YYYY-MM-DD"}: var/host-config.json on
//    the host wins; else the repo proposal Memory-Workflow/nightly-chain-independence/host-config.json (written only on the
//    owner's typed approval — Agent-Project-Backup Sys.scripts/lib/host_transfer_gate.py); else dry.
//    Unreadable ⇒ off (fail closed).

const TG_HOST_PLAN_SLOT = [187, 210];          // Tehran minutes: 03:07 ≤ t < 03:30
const TG_HOST_PLAN_MAX_ATTEMPTS = 4;
const TG_HOST_PLAN_RETRY_S = 120;              // backoff per failed attempt
const TG_HOST_PLAN_PAGE = 3000;                // the chain `history` op's own cap per call
const TG_HOST_PLAN_MAX_PAGES = 20;              // 60k messages back: anything deeper is a broken floor, not a night
const TG_HOST_PLAN_SOURCE_HINT = 'instant saver';

function tg_tehran_tz(): DateTimeZone { return new DateTimeZone('+03:30'); } // nightly_pool.TEHRAN is a fixed +03:30

const TG_HOST_CONFIG_REPO = 'Memory-Workflow/nightly-chain-independence/host-config.json';
const TG_HOST_CONFIG_POLL_S = 300;

/** Tick (CLI): cache the repo proposal in var/host-config-repo.json. 404 ⇒ {} (defaults); a transient error keeps the
 *  last known copy; a 200 that is not a JSON object ⇒ null (read as off). Never throws. */
function tg_host_config_sync(?int $now = null): void {
    if (PHP_SAPI !== 'cli' || !function_exists('tg_gh')) return;
    $now ??= time();
    $f = tg_var('host-config-repo.json');
    $c = tg_read_json($f) ?? [];
    if ($now - (int) ($c['polled'] ?? 0) < TG_HOST_CONFIG_POLL_S) return;
    try {
        [$code, $doc] = tg_gh('GET', '/repos/' . TG_REPO . '/contents/' . TG_HOST_CONFIG_REPO . '?ref=main');
        $out = ['polled' => $now, 'code' => $code];
        if ($code === 200) { $j = json_decode((string) base64_decode((string) ($doc['content'] ?? '')), true); $out['config'] = is_array($j) ? $j : null; }
        elseif ($code === 404) $out['config'] = [];
        else $out['config'] = array_key_exists('config', $c) ? $c['config'] : [];
        tg_write_json($f, $out);
    } catch (Throwable $e) { tg_log('chain', 'host config sync error ' . get_class($e)); }
}

/** The switch: local file wins, else the repo proposal, else defaults. */
function tg_host_config(): array {
    $f = tg_var('host-config.json');
    $c = ['mode' => 'dry', 'limit' => 0, 'plan_date' => null, 'source' => 'default'];
    if (is_file($f)) { $j = tg_read_json($f); $src = 'file'; }
    else {
        $r = tg_read_json(tg_var('host-config-repo.json'));
        if ($r === null || !array_key_exists('config', $r) || $r['config'] === []) return $c;
        $j = is_array($r['config']) ? $r['config'] : null; $src = 'repo';
    }
    if ($j === null) return ['mode' => 'off', 'source' => 'unreadable'] + $c;
    $m = (string) ($j['mode'] ?? 'dry');
    $c['mode'] = in_array($m, ['off', 'dry', 'live'], true) ? $m : 'off';
    $c['limit'] = max(0, (int) ($j['limit'] ?? 0));
    $pd = (string) ($j['plan_date'] ?? '');
    $c['plan_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) && DateTimeImmutable::createFromFormat('!Y-m-d', $pd) ? $pd : null;
    $c['source'] = $src;
    return $c;
}

/** nightly_pool.target_date(): Tehran today, but before 03:00 the night has not ended yet ⇒ yesterday. */
function tg_host_target_date(int $now): string {
    $t = (new DateTimeImmutable('@' . $now))->setTimezone(tg_tehran_tz());
    if ((int) $t->format('H') < 3) $t = $t->modify('-1 day');
    return $t->format('Y-m-d');
}
/** nightly_pool.window(): [since, until) as unix seconds. */
function tg_host_window(string $date): array {
    $until = new DateTimeImmutable($date . ' 03:00:00', tg_tehran_tz());
    return [$until->modify('-1 day')->getTimestamp(), $until->getTimestamp()];
}
function tg_host_iso(int $t): string { return (new DateTimeImmutable('@' . $t))->setTimezone(tg_tehran_tz())->format('Y-m-d\TH:i:sP'); }

function tg_host_plan_state(): array { return tg_read_json(tg_var('host-plan.json')) ?? []; }
function tg_host_plan_mark(string $date, array $patch): void {
    $s = tg_host_plan_state();
    $s[$date] = array_merge($s[$date] ?? [], $patch, ['updated' => time()]);
    ksort($s);
    while (count($s) > 30) array_shift($s);
    tg_write_json(tg_var('host-plan.json'), $s);
}

/** Tick hook (CLI, no library): decide whether a plan is due and enqueue one `host_plan` job. Never throws.
 *  A manual `plan_date` runs once on its own record and never suspends the nightly slot. Returns what it did. */
function tg_host_plan_tick(?int $now = null): string {
    if (PHP_SAPI !== 'cli') return 'not-cli';
    $now ??= time();
    try {
        $cfg = tg_host_config();
        if ($cfg['mode'] === 'off') return 'off';
        $target = tg_host_target_date($now);
        if ($cfg['plan_date'] !== null) {
            if ($cfg['plan_date'] > $target) tg_log('chain', 'host plan: plan_date window not closed yet');
            else { $r = tg_host_plan_step($cfg['plan_date'], true, $now); if (!in_array($r, ['done', 'gave-up', 'backoff'], true)) return 'manual:' . $r; }
        }
        return tg_host_plan_step($target, false, $now);
    } catch (Throwable $e) {
        tg_log('chain', 'host plan tick error ' . get_class($e));
        return 'error';
    }
}

function tg_host_plan_step(string $date, bool $manual, int $now): string {
    $rec = tg_host_plan_state()[$date] ?? null;
    if (($rec['state'] ?? '') === 'done') return 'done';
    if (($rec['state'] ?? '') === 'queued') {
        $st = tg_read_json(tg_job_dir((string) ($rec['job'] ?? '')) . '/status.json') ?? [];
        if (in_array($st['state'] ?? '', ['queued', 'running'], true)) return 'busy';
        if (($st['state'] ?? '') === 'done') { tg_host_plan_mark($date, ['state' => 'done']); return 'done'; }
        // killed beyond the tick's resume cap, or the job folder is gone: the attempt is spent
        tg_host_plan_mark($date, ['state' => 'failed', 'error' => (string) ($st['error'] ?? 'job lost'), 'next_at' => $now + TG_HOST_PLAN_RETRY_S]);
        $rec = tg_host_plan_state()[$date];
    }
    if ($rec === null) {
        $t = (new DateTimeImmutable('@' . $now))->setTimezone(tg_tehran_tz());
        $hm = (int) $t->format('H') * 60 + (int) $t->format('i');
        $inSlot = $hm >= TG_HOST_PLAN_SLOT[0] && $hm < TG_HOST_PLAN_SLOT[1] && $t->format('Y-m-d') === $date;
        if (!$manual && !$inSlot) return 'not-due';
    } else {
        if ((int) ($rec['attempts'] ?? 0) >= TG_HOST_PLAN_MAX_ATTEMPTS) {
            if (($rec['state'] ?? '') !== 'gave_up') { tg_host_plan_mark($date, ['state' => 'gave_up']); tg_log('chain', 'host plan gave up night=' . $date); }
            return 'gave-up';
        }
        if ($now < (int) ($rec['next_at'] ?? 0)) return 'backoff';
    }
    if (count(glob(tg_var('queue') . '/*.json') ?: []) >= TG_QUEUE_CAP) return 'queue-full';
    $id = tg_id();
    $n = (int) ($rec['attempts'] ?? 0) + 1;
    tg_status($id, ['id' => $id, 'job' => 'host_plan', 'state' => 'queued', 'created_at' => $now, 'role' => 'internal']);
    tg_write_json(tg_dir(tg_var('queue')) . '/' . $now . '-host_plan-' . $id . '.json',
        ['id' => $id, 'job' => 'host_plan', 'args' => ['date' => $date], 'role' => 'internal', 'attempt' => 0, 'not_before' => 0]);
    tg_host_plan_mark($date, ['state' => 'queued', 'job' => $id, 'attempts' => $n, 'manual' => $manual]);
    tg_log('chain', 'host plan queued night=' . $date . ' attempt=' . $n . ($manual ? ' manual' : ''));
    return 'queued';
}

/** The allowlisted source chat: kind "source" in var/chain-chats.json (several ⇒ the one named like Instant Saver). */
function tg_host_plan_source(): ?int {
    $src = [];
    foreach (tg_chain_chats()['chats'] as $id => $m) if (($m['kind'] ?? '') === 'source') $src[(int) $id] = $m;
    if (count($src) === 1) return array_key_first($src);
    foreach ($src as $id => $m) {
        foreach ([$m['title'] ?? '', $m['label'] ?? ''] as $n) if ($n !== '' && str_contains(mb_strtolower($n), TG_HOST_PLAN_SOURCE_HINT)) return $id;
    }
    return null;
}

/** Newest-first history down to $floor (unix), through the chain `history` op (allowlist + peer heal + floor stop). */
function tg_host_plan_collect($api, int $peer, int $floor, ?callable $hb = null, int $page = TG_HOST_PLAN_PAGE): array {
    $out = []; $off = 0;
    for ($p = 0; $p < TG_HOST_PLAN_MAX_PAGES; $p++) {
        $r = tg_chain_op_healed($api, 'history', ['peer' => $peer, 'limit' => $page, 'floor_date' => $floor, 'offset_id' => $off], null);
        $ms = $r['messages'] ?? [];
        foreach ($ms as $m) $out[] = $m;
        if ($hb) $hb(['phase' => 'history', 'read' => count($out)]);
        if (count($ms) < $page) return $out;   // floor reached or the chat's first message
        $off = (int) $ms[count($ms) - 1]['id'];
    }
    throw new RuntimeException('history deeper than ' . (TG_HOST_PLAN_MAX_PAGES * $page) . ' messages');
}

const TG_SOURCE_URL_RX = '~https?://(?:www\\.)?(?:instagram\\.com|instagr\\.am)/[^\\s)\\]>"\']+~i';
/** telegram_pool.source_url(): media texts first, then the text before and after; no guess. */
function tg_host_source_url(array $b): ?string {
    $texts = array_map('tg_m_text', $b['media']);
    $texts[] = $b['text_before'] ? tg_m_text($b['text_before']) : '';
    $texts[] = $b['text_after'] ? tg_m_text($b['text_after']) : '';
    foreach ($texts as $t) if (preg_match(TG_SOURCE_URL_RX, $t, $mm)) return rtrim($mm[0], '.,،');
    return null;
}

/** Pure: newest-first messages ⇒ the night's bundles, in Python's order (old → new). */
function tg_host_plan_build(array $newestFirst, int $since, int $until): array {
    $bundles = [];
    foreach (tg_group_bundles(array_reverse($newestFirst)) as $b) {
        $ids = array_map(fn($m) => (int) $m['id'], $b['media']);
        $types = array_map('tg_kind_group', $b['media']);
        $first = min(array_map(fn($m) => (int) ($m['date'] ?? 0), $b['media']));
        if (!($first >= $since && $first < $until)) continue;
        if (!array_intersect($types, ['video', 'photo', 'document'])) continue;   // a-bundle-without-media-is-not-a-package
        $bundles[] = ['key' => 'b' . min($ids), 'media_ids' => $ids, 'types' => $types, 'source_url' => tg_host_source_url($b)];
    }
    return $bundles;
}

/** Exclusion, archive check and the repo write. $archived(key): true|false|null(unknown); $put(path, content, msg). */
function tg_host_plan_finish(string $date, array $bundles, array $meta, callable $archived, callable $put): array {
    [$since, $until] = tg_host_window($date);
    $keep = []; $excluded = [];
    foreach ($bundles as $b) {
        $a = $archived($b['key']);
        if ($a === null) throw new RuntimeException('archive check failed for ' . $b['key']);
        if ($a) $excluded[] = $b['key']; else $keep[] = $b;
    }
    $doc = ['date' => $date, 'since' => tg_host_iso($since), 'until' => tg_host_iso($until),
            'keys' => array_map(fn($b) => $b['key'], $keep), 'bundles' => $keep,
            'window_keys' => array_map(fn($b) => $b['key'], $bundles), 'excluded_archived' => $excluded,
            'mode' => 'dry'] + $meta + ['at_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'host_version' => ''];
    $path = 'Memory-Workflow/nightly-pool/host/' . $date . '.json';
    $r = $put($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        'tg-host: night plan ' . $date . ' (dry, ' . count($keep) . ' keys)');
    if (empty($r['ok'])) throw new RuntimeException('repo write failed code=' . (int) ($r['code'] ?? 0));
    return ['path' => $path, 'keys' => $doc['keys'], 'bundles' => $keep, 'excluded_archived' => $excluded, 'write' => $r];
}

/** nightly-pool.yml plan step: `git cat-file -e origin/main:$HOME_DIR/archive/$k.json`. */
function tg_host_plan_archived(string $key): ?bool {
    if (!preg_match('/^b[0-9]{1,12}$/', $key)) return null;
    [$c, ] = tg_gh('GET', '/repos/' . TG_REPO . '/contents/Memory-Workflow/nightly-pool/archive/' . $key . '.json?ref=main');
    return $c === 200 ? true : ($c === 404 ? false : null);
}

/** Internal job (no door role): plan one night (read-only). */
function job_host_plan(array $a, callable $hb, string $role = 'read'): array {
    if ($role !== 'internal') throw new RuntimeException('internal job');
    $date = (string) ($a['date'] ?? '');
    try {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('bad date');
        $cfg = tg_host_config();
        $peer = tg_host_plan_source();
        if ($peer === null) throw new RuntimeException('NO_SOURCE_CHAT: no single kind=source chat in chain-chats.json');
        [$since, $until] = tg_host_window($date);
        $msgs = tg_mp_with(tg_session_dir(), fn($api) => ['m' => tg_host_plan_collect($api, $peer, $since - 3600, $hb)], 600)['m'];
        $bundles = tg_host_plan_build($msgs, $since, $until);
        $lease = tg_lease_load()['scopes']['tg'] ?? null;
        $meta = ['mode_requested' => $cfg['mode'], 'limit' => $cfg['limit'], 'messages_read' => count($msgs),
                 'tg_lease_held' => tg_lease_live($lease, time()),
                 'host_version' => (string) ((tg_read_json(TG_BASE . '/var/deployed.json') ?? [])['version'] ?? '')];
        $hb(['phase' => 'write', 'bundles' => count($bundles)]);
        $res = tg_host_plan_finish($date, $bundles, $meta, 'tg_host_plan_archived', 'tg_repo_put');
    } catch (Throwable $e) {
        if ($date !== '') tg_host_plan_mark($date, ['state' => 'failed', 'error' => get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 120), 'next_at' => time() + TG_HOST_PLAN_RETRY_S]);
        throw $e;
    }
    tg_host_plan_mark($date, ['state' => 'done', 'keys' => count($res['keys']), 'code' => (int) ($res['write']['code'] ?? 0),
        'bundles' => $res['bundles'], 'peer' => $peer]);
    tg_log('chain', 'host plan done night=' . $date . ' keys=' . count($res['keys']));
    return $res;
}
