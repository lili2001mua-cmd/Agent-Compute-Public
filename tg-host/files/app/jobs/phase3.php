<?php
// Phase-3 jobs: the four read-only Telegram jobs, ported from Agent-Project-Backup/Sys.scripts/telegram_*.py.
// The host only talks to Telegram and stages media; the client (Sys.scripts/tg-host.py) writes every repo
// file with the same names and shapes the Python scripts wrote. Media goes to public_html/m/<128-bit>/
// (served statically, never through PHP) and expires after TG_MEDIA_TTL_S.
// Telethon parity: msg.photo / msg.document also return a link preview's photo/document, msg.video skips
// round messages, MessageEmpty is skipped, and dialogs are scanned in Telegram's own order (pinned first).
// Read-key lock: a read key may only name an allowlisted chat (and fetch only "saved"); anything else needs admin.

const TG_READ_CHATS = ['instant saver'];

function tg_require_scope(string $role, string $query): void {
    if ($role === 'admin') return;
    if (!in_array(mb_strtolower(trim($query)), TG_READ_CHATS, true)) throw new RuntimeException('read key: chat not allowlisted (admin key required)');
}

// ---- Telethon-equivalent message accessors -------------------------------------------------------
function tg_m_media(array $m): ?array { $md = $m['media'] ?? null; return (is_array($md) && ($md['_'] ?? '') !== 'messageMediaEmpty') ? $md : null; }
function tg_m_photo(array $m): ?array {
    $md = tg_m_media($m); if (!$md) return null;
    if ($md['_'] === 'messageMediaPhoto') return (($md['photo']['_'] ?? '') === 'photo') ? $md['photo'] : null;
    if ($md['_'] === 'messageMediaWebPage' && ($md['webpage']['_'] ?? '') === 'webPage') { $p = $md['webpage']['photo'] ?? null; return (is_array($p) && $p['_'] === 'photo') ? $p : null; }
    return null;
}
function tg_m_document(array $m): ?array {
    $md = tg_m_media($m); if (!$md) return null;
    if ($md['_'] === 'messageMediaDocument') return (($md['document']['_'] ?? '') === 'document') ? $md['document'] : null;
    if ($md['_'] === 'messageMediaWebPage' && ($md['webpage']['_'] ?? '') === 'webPage') { $d = $md['webpage']['document'] ?? null; return (is_array($d) && $d['_'] === 'document') ? $d : null; }
    return null;
}
/** Telethon _document_by_attribute: the first attribute of that kind decides. */
function tg_doc_by_attr(array $m, string $kind, callable $cond): ?array {
    $d = tg_m_document($m); if (!$d) return null;
    foreach ($d['attributes'] ?? [] as $a) { if (($a['_'] ?? '') === $kind) return $cond($a) ? $d : null; }
    return null;
}
function tg_m_video(array $m): ?array { return tg_doc_by_attr($m, 'documentAttributeVideo', fn($a) => empty($a['round_message'])); }
function tg_m_voice(array $m): ?array { return tg_doc_by_attr($m, 'documentAttributeAudio', fn($a) => !empty($a['voice'])); }
function tg_m_text(array $m): string { return (string) ($m['message'] ?? ''); }
function tg_m_filename(array $m): ?string {
    $d = tg_m_document($m); if (!$d) return null;
    foreach ($d['attributes'] ?? [] as $a) { if (($a['_'] ?? '') === 'documentAttributeFilename') return (string) $a['file_name']; }
    return null;
}
/** read_recent.media_kind */
function tg_kind_recent(array $m): string {
    if (tg_m_video($m)) return 'VIDEO';
    if (tg_m_photo($m)) return 'PHOTO';
    if (tg_m_voice($m)) return 'VOICE';
    if (tg_m_document($m)) return 'DOC';
    if (tg_m_media($m)) return 'MEDIA';
    return 'TEXT';
}
/** tg_grouping.media_type */
function tg_kind_group(array $m): string {
    if (tg_m_video($m)) return 'video';
    if (tg_m_photo($m)) return 'photo';
    if (tg_m_document($m)) return 'document';
    return 'other';
}
function tg_m_is_text(array $m): bool { return tg_m_text($m) !== '' && !tg_m_media($m); }
function tg_date(int $t): string { return gmdate('Y-m-d H:i:s', $t) . '+00:00'; } // Python str(datetime) in UTC

// ---- grouping: line-for-line port of Sys.scripts/tg_grouping.py (lock carousel-continues-across-full-albums) ----
const TG_ALBUM_GAP_S = 15;
const TG_ALBUM_MAX = 10;
function tg_group_bundles(array $msgs): array {
    $batches = []; $cur = []; $curGid = null; $lastText = null;
    $flush = function ($after) use (&$batches, &$cur, &$curGid, &$lastText) {
        if ($cur) $batches[] = ['text_before' => $lastText, 'media' => $cur, 'text_after' => $after];
        $cur = []; $curGid = null;
    };
    foreach ($msgs as $m) {
        if (tg_m_is_text($m)) { $flush($m); $lastText = $m; continue; }
        if (!tg_m_media($m)) continue;
        $gid = $m['grouped_id'] ?? null;
        if (!$cur) { $cur = [$m]; $curGid = $gid; continue; }
        $prev = $cur[count($cur) - 1];
        $near = abs(($m['date'] ?? 0) - ($prev['date'] ?? 0)) <= TG_ALBUM_GAP_S;
        if (tg_kind_group($m) === 'other') $same = false;
        elseif ($gid !== null && $curGid !== null && $gid !== $curGid) {
            $tail = 0; for ($i = count($cur) - 1; $i >= 0 && ($cur[$i]['grouped_id'] ?? null) === $curGid; $i--) $tail++;
            $same = $near && $tail >= TG_ALBUM_MAX;
        } elseif ($gid !== null && $curGid !== null) $same = true;
        else $same = $near;
        if ($same) { $cur[] = $m; if ($gid !== null) $curGid = $gid; }
        else { $flush(null); $cur = [$m]; $curGid = $gid; }
    }
    $flush(null);
    return $batches;
}

/** The seven tg_grouping self-tests, same inputs and expectations. */
function tg_grouping_selftest(): array {
    $M = function (int $id, int $t, $gid = null, string $kind = 'photo', ?string $text = null): array {
        $m = ['_' => 'message', 'id' => $id, 'date' => 1790000000 + $t, 'message' => $text ?? ''];
        if ($gid !== null) $m['grouped_id'] = $gid;
        if ($kind === 'photo') $m['media'] = ['_' => 'messageMediaPhoto', 'photo' => ['_' => 'photo']];
        if ($kind === 'video') $m['media'] = ['_' => 'messageMediaDocument', 'document' => ['_' => 'document', 'attributes' => [['_' => 'documentAttributeVideo']]]];
        if ($kind === 'other') $m['media'] = ['_' => 'messageMediaWebPage', 'webpage' => ['_' => 'webPageEmpty']];
        return $m;
    };
    $sizes = fn(array $ms) => array_map(fn($b) => count($b['media']), tg_group_bundles($ms));
    $r = [];
    $v = [$M(7052, 0, null, 'video')]; for ($i = 0; $i < 10; $i++) $v[] = $M(7053 + $i, 1, 'A', 'video'); for ($i = 0; $i < 9; $i++) $v[] = $M(7063 + $i, 3, 'B', 'video');
    $r['carousel_20'] = $sizes($v) === [20];
    $p = [$M(6693, 0, null, 'other')]; for ($i = 0; $i < 10; $i++) $p[] = $M(6694 + $i, 1, 'A'); for ($i = 0; $i < 2; $i++) $p[] = $M(6704 + $i, 2, 'B');
    $r['photo_13'] = $sizes($p) === [13];
    $two = [$M(1, 0, null, 'other')]; for ($i = 0; $i < 10; $i++) $two[] = $M(2 + $i, 1, 'A'); $two[] = $M(20, 6, null, 'other'); for ($i = 0; $i < 3; $i++) $two[] = $M(21 + $i, 7, 'B');
    $r['header_splits'] = $sizes($two) === [11, 4];
    $part = []; for ($i = 0; $i < 4; $i++) $part[] = $M(1, 0, 'A', 'video'); for ($i = 0; $i < 3; $i++) $part[] = $M(9, 2, 'B', 'video');
    $r['partial_album'] = $sizes($part) === [4, 3];
    $far = []; for ($i = 0; $i < 10; $i++) $far[] = $M(1, 0, 'A'); for ($i = 0; $i < 5; $i++) $far[] = $M(20, 70, 'B');
    $r['far_album'] = $sizes($far) === [10, 5];
    $txt = []; for ($i = 0; $i < 10; $i++) $txt[] = $M(1, 0, 'A'); $txt[] = $M(15, 1, null, 'text', 'caption'); for ($i = 0; $i < 3; $i++) $txt[] = $M(16, 2, 'B');
    $r['text_separates'] = $sizes($txt) === [10, 3];
    $chain = []; for ($i = 0; $i < 30; $i++) $chain[] = $M($i, intdiv($i, 10), 'ABC'[intdiv($i, 10)]);
    $r['three_full'] = $sizes($chain) === [30];
    return $r;
}

// ---- Telegram helpers ----------------------------------------------------------------------------
/** Marked (Bot-API) id of a peer: MadelineProto 8 already hands many peers over as ints. */
function tg_peer_id($p): int {
    if (is_int($p)) return $p;
    return match ($p['_'] ?? '') {
        'peerUser' => (int) $p['user_id'],
        'peerChat' => -(int) $p['chat_id'],
        'peerChannel' => -1000000000000 - (int) $p['channel_id'],
        default => throw new RuntimeException('unknown peer'),
    };
}

/** First dialog (Telegram order, all folders) whose display name contains $query, case-insensitive. */
function tg_find_dialog($api, string $query): ?array {
    // ک1: host-owned allowlist by numeric id first; only the admin key may fall back to scanning dialog titles
    if (function_exists('tg_chain_find') && ($hit = tg_chain_find($query))) return ['id' => (int) $hit['id'], 'name' => (string) ($hit['title'] ?? $hit['label'] ?? '')];
    if (($GLOBALS['tg_role'] ?? 'read') !== 'admin') return null;
    $q = mb_strtolower($query);
    $off = ['date' => 0, 'id' => 0, 'peer' => ['_' => 'inputPeerEmpty']];
    for ($page = 0; $page < 50; $page++) {
        $r = $api->messages->getDialogs(offset_date: $off['date'], offset_id: $off['id'], offset_peer: $off['peer'], limit: 100, hash: 0);
        $dialogs = $r['dialogs'] ?? [];
        if (!$dialogs) return null;
        $names = [];
        foreach ($r['users'] ?? [] as $u) $names[(int) $u['id']] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
        foreach ($r['chats'] ?? [] as $c) {
            $isChannel = str_starts_with((string) ($c['_'] ?? ''), 'channel');
            $names[$isChannel ? -1000000000000 - (int) $c['id'] : -(int) $c['id']] = (string) ($c['title'] ?? '');
        }
        $msgs = [];
        foreach ($r['messages'] ?? [] as $mm) { if (isset($mm['peer_id'], $mm['id'])) $msgs[tg_peer_id($mm['peer_id']) . ':' . $mm['id']] = $mm; }
        foreach ($dialogs as $d) {
            $id = tg_peer_id($d['peer']);
            $name = $names[$id] ?? '';
            if (str_contains(mb_strtolower($name), $q)) return ['id' => $id, 'name' => $name];
        }
        if (count($dialogs) < 100 || ($r['_'] ?? '') === 'messages.dialogs') return null;
        $last = end($dialogs);
        $lid = tg_peer_id($last['peer']);
        $lm = $msgs[$lid . ':' . $last['top_message']] ?? null;
        if (!$lm) return null;
        $off = ['date' => (int) $lm['date'], 'id' => (int) $lm['id'], 'peer' => $lid];
    }
    return null;
}

/** iter_messages(limit): newest first, MessageEmpty skipped. */
function tg_history($api, $peer, int $limit, ?callable $hb = null): array {
    $out = []; $offId = 0;
    while (count($out) < $limit) {
        $n = min(100, $limit - count($out));
        $r = $api->messages->getHistory(peer: $peer, offset_id: $offId, limit: $n);
        $ms = $r['messages'] ?? [];
        foreach ($ms as $m) { $offId = (int) $m['id']; if (($m['_'] ?? '') !== 'messageEmpty') $out[] = $m; }
        if ($hb) $hb(['phase' => 'history', 'read' => count($out)]);
        if (count($ms) < $n) break;
    }
    return array_slice($out, 0, $limit);
}

/** A fresh unguessable media folder for this job. */
function tg_media_dir(): string {
    $dir = TG_BASE . '/public_html/m/' . bin2hex(random_bytes(16));
    tg_dir($dir); @chmod($dir, 0755);
    file_put_contents($dir . '/.expires', (string) (time() + TG_MEDIA_TTL_S));
    return $dir;
}

/** Download the message's media (Telethon download_media semantics, link previews included) into $dir. */
function tg_download($api, array $m, string $dir): array {
    $doc = tg_m_document($m); $photo = $doc ? null : tg_m_photo($m);
    if (!$doc && !$photo) throw new RuntimeException('no downloadable media');
    $size = $doc ? (int) ($doc['size'] ?? 0) : 0;
    if ($size && tg_usage_bytes() + $size > TG_DISK_CAP_BYTES) throw new RuntimeException('disk cap');
    $path = $dir . '/' . $m['id'] . '.bin';
    $api->downloadToFile($doc ? ['_' => 'messageMediaDocument', 'document' => $doc] : ['_' => 'messageMediaPhoto', 'photo' => $photo], $path);
    @chmod($path, 0644);
    return ['url_path' => substr($path, strlen(TG_BASE . '/public_html')), 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path),
            'mime' => $doc ? ($doc['mime_type'] ?? null) : 'image/jpeg', 'is_photo' => (bool) $photo];
}

// ---- jobs ----------------------------------------------------------------------------------------
function job_read_recent(array $a, callable $hb, string $role = 'read'): array {
    tg_require_scope($role, $a['query']);
    return tg_mp_with(tg_session_dir(), function ($api) use ($a, $hb) {
        $d = tg_find_dialog($api, $a['query']);
        if (!$d) return ['error' => 'NO_CHAT'];
        $rows = array_reverse(tg_history($api, $d['id'], (int) $a['limit'], $hb));
        return ['chat' => $d['name'], 'chat_id' => $d['id'], 'rows' => array_map(fn($m) => ['id' => $m['id'], 'date' => tg_date((int) $m['date']),
            'kind' => tg_kind_recent($m), 'text' => tg_m_text($m)], $rows)];
    }, 200);
}

function job_last_video(array $a, callable $hb, string $role = 'read'): array {
    tg_require_scope($role, $a['query']);
    return tg_mp_with(tg_session_dir(), function ($api) use ($a, $hb) {
        $d = tg_find_dialog($api, $a['query']);
        if (!$d) return ['error' => 'NO_CHAT'];
        $scanned = 0; $found = null;
        foreach (tg_history($api, $d['id'], (int) $a['max_scan'], $hb) as $m) {
            $scanned++;
            $doc = tg_m_document($m);
            if (tg_m_video($m) || ($doc && str_starts_with((string) ($doc['mime_type'] ?? ''), 'video/'))) { $found = $m; break; }
        }
        if (!$found) return ['error' => 'NO_VIDEO', 'scanned' => $scanned];
        $doc = tg_m_document($found);
        $meta = ['size' => $doc['size'] ?? null, 'mime' => $doc['mime_type'] ?? null];
        foreach ($doc['attributes'] ?? [] as $at) {
            if (($at['_'] ?? '') === 'documentAttributeVideo') { $meta['duration_s'] = $at['duration'] ?? null; $meta['w'] = $at['w'] ?? null; $meta['h'] = $at['h'] ?? null; }
        }
        $hb(['phase' => 'download', 'bytes' => $meta['size']]);
        $file = tg_download($api, $found, tg_media_dir());
        return ['chat' => $d['name'], 'chat_id' => $d['id'], 'msg_id' => $found['id'], 'date' => tg_date((int) $found['date']),
                'caption' => tg_m_text($found), 'filename' => tg_m_filename($found), 'scanned' => $scanned, 'meta' => $meta, 'file' => $file];
    }, 1200);
}

const TG_CODE_EXTS = ['py','pyw','ipynb','js','mjs','cjs','ts','tsx','jsx','vue','svelte','java','kt','kts','scala','groovy','gradle','c','h','cc','cpp','cxx',
    'hpp','hxx','cs','go','rs','rb','php','swift','m','mm','dart','lua','pl','pm','r','jl','hs','ex','exs','erl','clj','cljs','sh','bash','zsh','fish','ps1','bat',
    'cmd','sql','html','htm','css','scss','sass','less','json','json5','yaml','yml','toml','ini','cfg','conf','env','xml','md','rst','txt','tex','proto','graphql',
    'gql','tf','hcl','dockerfile','makefile','cmake','patch','diff','asm','s','v','sv','vhd','f','f90','nim','zig','sol','cshtml','razor','vb'];
function tg_ext_of(?string $name): string {
    if (!$name) return '';
    $b = mb_strtolower(basename($name));
    return str_contains($b, '.') ? substr($b, strrpos($b, '.') + 1) : $b;
}

function job_fetch(array $a, callable $hb, string $role = 'read'): array {
    if ($role !== 'admin' && $a['target'] !== 'saved') throw new RuntimeException('read key: only target "saved" (admin key required)');
    return tg_mp_with(tg_session_dir(), function ($api) use ($a, $hb) {
        $me = $api->getSelf();
        $targets = [];
        if ($a['target'] === 'saved') $targets[] = ['label' => 'Saved_Messages', 'peer' => 'me'];
        elseif ($a['target'] === 'all') {
            foreach ($api->getDialogIds() as $id) { $info = $api->getInfo($id); $targets[] = ['label' => (string) ($info['Chat']['title'] ?? trim(($info['User']['first_name'] ?? '') . ' ' . ($info['User']['last_name'] ?? '')) ?: (string) $id), 'peer' => $id, 'raw_label' => true]; }
        } else {
            $t = preg_match('/^-?\d+$/', $a['target']) ? (int) $a['target'] : $a['target'];
            $info = $api->getInfo($t);
            $targets[] = ['label' => (string) ($info['Chat']['title'] ?? $info['User']['username'] ?? $info['Chat']['username'] ?? $info['bot_api_id'] ?? 'target'), 'peer' => $info['bot_api_id']];
        }
        $dir = tg_media_dir();
        $chats = [];
        foreach ($targets as $t) {
            $rec = ['chat' => $t['label'], 'files' => [], 'snippets' => [], 'scanned' => 0];
            foreach (tg_history($api, $t['peer'], (int) $a['per_chat'], $hb) as $m) {
                $rec['scanned']++;
                if (tg_m_document($m)) {
                    $fname = tg_m_filename($m) ?? ('doc_' . $m['id']);
                    $e = tg_ext_of($fname);
                    if (in_array($e, TG_CODE_EXTS, true) || $a['all_files'] === '1') {
                        try {
                            $f = tg_download($api, $m, $dir);
                            $rec['files'][] = ['id' => $m['id'], 'fname' => $fname, 'ext' => $e, 'date' => tg_date((int) $m['date']), 'size' => tg_m_document($m)['size'] ?? null, 'file' => $f];
                        } catch (Throwable $ex) { $rec['errors'][] = ['id' => $m['id'], 'error' => mb_substr($ex->getMessage(), 0, 120)]; }
                    }
                }
                $text = tg_m_text($m);
                if ($text !== '' && preg_match_all('/```([^\n`]*)\n(.*?)```/su', $text, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $i => $blk) $rec['snippets'][] = ['id' => $m['id'], 'i' => $i, 'lang' => $blk[1], 'code' => $blk[2], 'date' => tg_date((int) $m['date'])];
                }
            }
            $chats[] = $rec;
        }
        return ['account' => ['id' => $me['id'] ?? null, 'username' => $me['username'] ?? null, 'name' => $me['first_name'] ?? null], 'target' => $a['target'], 'chats' => $chats];
    }, 1500);
}

function job_batches(array $a, callable $hb, string $role = 'read'): array {
    tg_require_scope($role, $a['query']);
    return tg_mp_with(tg_session_dir(), function ($api) use ($a, $hb) {
        $d = tg_find_dialog($api, $a['query']);
        if (!$d) return ['error' => 'NO_CHAT'];
        $msgs = array_reverse(tg_history($api, $d['id'], (int) $a['scan'], $hb));
        $batches = tg_group_bundles($msgs);
        $total = count($batches); $sets = (int) $a['sets']; $offset = (int) $a['offset'];
        $start = max(0, $total - $offset - $sets); $end = $total - $offset;
        $out = ['chat' => $d['name'], 'chat_id' => $d['id'], 'total' => $total, 'start' => $start, 'end' => $end, 'selftest' => tg_grouping_selftest()];
        if ($a['dry'] === '1') {
            // golden comparison input: the same messages, reduced to what tg_grouping reads
            $out['messages'] = array_map(fn($m) => ['id' => $m['id'], 'date' => (int) $m['date'], 'grouped_id' => isset($m['grouped_id']) ? (string) $m['grouped_id'] : null,
                'is_text' => tg_m_is_text($m), 'has_media' => (bool) tg_m_media($m), 'kind' => tg_kind_group($m)], $msgs);
            $out['bundles'] = array_map(fn($b) => array_map(fn($m) => $m['id'], $b['media']), $batches);
            return $out;
        }
        if (!$total || $end <= 0) return $out + ['error' => 'NO_BATCH'];
        $dir = tg_media_dir();
        $out['sets'] = [];
        foreach (array_slice($batches, $start, $end - $start) as $i => $b) {
            if ($a['sleep'] && $i > 0) { $hb(['phase' => 'sleep', 'set' => $i + 1]); \Amp\delay((int) $a['sleep']); }
            $media = [];
            foreach ($b['media'] as $m) {
                $hb(['phase' => 'download', 'set' => $i + 1, 'msg' => $m['id']]);
                $rec = ['id' => $m['id'], 'type' => tg_kind_group($m), 'fname' => tg_m_filename($m)];
                try { $rec['file'] = tg_download($api, $m, $dir); } catch (Throwable $ex) { $rec['error'] = mb_substr($ex->getMessage(), 0, 120); }
                $media[] = $rec;
            }
            $out['sets'][] = ['text_before' => $b['text_before'] ? tg_m_text($b['text_before']) : null,
                              'text_after' => $b['text_after'] ? tg_m_text($b['text_after']) : null, 'media' => $media];
        }
        // what this host itself produced — the only ids job_delete will ever accept (72 h, see phase5.php)
        $ids = [];
        foreach ($out['sets'] as $st) foreach ($st['media'] as $md) { if (isset($md['file'])) $ids[] = (int) $md['id']; }
        tg_write_json(tg_var('produced/' . time() . '-' . bin2hex(random_bytes(4)) . '.json'), ['chat_id' => $d['id'], 'ids' => $ids, 'at' => time()]);
        return $out;
    }, 1500);
}
