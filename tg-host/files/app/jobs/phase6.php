<?php
// Phase 6: the four-step chain's Telegram side (Agent-Project-Backup Memory-Workflow/chain-host-migration, v4).
// ⛔ Named steps only — no generic RPC (commission row ک3). Every peer argument must be in var/chain-chats.json, which
//    only the host writes: seeded by the admin job `chain_admin` and grown only by a successful `create_channel` (ک4).
// ⛔ Writes are idempotent across kills: each op with an `idem` is journalled in var/ops/ right after it succeeds and a
//    resumed job returns the journalled result instead of acting again (ک6); forwards also dedupe on
//    var/forwarded.jsonl by (key, source message id) (ک6, ک12). Deleting from the source needs a verified forward (ک5).
// ⛔ Rate caps are enforced here, not by the client (ک6); FLOOD_WAIT / PEER_FLOOD stops the batch and is reported.
// ⛔ A chain_ops job must present the current chain lease token (ک10); the lease lives in run.php (no MadelineProto).

const TG_CHAIN_MAX_CHATS = 10;
const TG_CHAIN_FILE_CAP = 50 * 1048576;
const TG_CHAIN_NIGHT_CAP = 400 * 1048576;
const TG_CHAIN_STAGE_CAP = 300 * 1048576;
const TG_CHAIN_UPLOAD_CAP = 20 * 1048576;
const TG_CHAIN_FWD_VERIFY_S = 7 * 86400;
const TG_CHAIN_OPS_BUDGET_S = 420;
// bucket => [limit, window seconds]
const TG_CHAIN_RATES = [
    'forward_day' => [300, 86400], 'create_day' => [1, 86400], 'create_week' => [3, 604800],
    'edit_hour' => [30, 3600], 'send_hour' => [60, 3600], 'delete_night' => [300, 86400], 'stage_hour' => [400, 3600],
    'bot_hour' => [120, 3600], 'drive_mint_day' => [12, 86400],
];
const TG_CHAIN_KINDS_WRITE = ['archive', 'control', 'panel', 'created'];

// ---- allowlist ----------------------------------------------------------------------------------
function tg_chain_chats(): array { return tg_read_json(tg_var('chain-chats.json')) ?? ['chats' => []]; }
function tg_chain_save(array $c): void { tg_write_json(tg_var('chain-chats.json'), $c); }
/** The numeric id of an allowlisted chat, or an exception. Accepts marked ids (int/str) only — never names (ک1). */
function tg_chain_peer($peer, array $kinds = []): int {
    if (!is_int($peer) && !(is_string($peer) && preg_match('/^-?\d{1,20}$/', $peer))) throw new RuntimeException('PEER_NOT_ALLOWED: peers are numeric ids');
    $id = (int) $peer;
    $meta = tg_chain_chats()['chats'][(string) $id] ?? null;
    if (!$meta) throw new RuntimeException('PEER_NOT_ALLOWED: ' . $id . ' is not a chain chat');
    if ($kinds && !in_array($meta['kind'], $kinds, true)) throw new RuntimeException('PEER_NOT_ALLOWED: ' . $id . ' is ' . $meta['kind']);
    return $id;
}
/** Allowlisted chat whose label/title contains $query (case-insensitive), or whose id equals it. */
function tg_chain_find(string $query): ?array {
    $q = mb_strtolower(trim($query));
    foreach (tg_chain_chats()['chats'] as $id => $m) {
        if ($q === (string) $id || $q === ltrim((string) $id, '-')) return ['id' => (int) $id] + $m;
    }
    foreach (tg_chain_chats()['chats'] as $id => $m) {
        foreach ([$m['title'] ?? '', $m['label'] ?? ''] as $name) { if ($name !== '' && str_contains(mb_strtolower($name), $q)) return ['id' => (int) $id] + $m; }
    }
    return null;
}

// ---- rates, journals, logs -----------------------------------------------------------------------
function tg_rate(string $bucket, int $n = 1): void {
    [$limit, $win] = TG_CHAIN_RATES[$bucket];
    $f = tg_var('chain-rate.json'); $r = tg_read_json($f) ?? [];
    $now = time(); $hits = array_values(array_filter($r[$bucket] ?? [], fn($t) => $t > $now - $win));
    if (count($hits) + $n > $limit) throw new RuntimeException("RATE_LIMIT: $bucket ($limit per {$win}s)");
    for ($i = 0; $i < $n; $i++) $hits[] = $now;
    $r[$bucket] = $hits; tg_write_json($f, $r);
}
function tg_night_bytes(int $add = 0): int {
    $f = tg_var('chain-bytes.json'); $r = tg_read_json($f) ?? [];
    $day = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->modify('-12 hours')->format('Y-m-d'); // a night = noon..noon Tehran
    $cur = (int) ($r[$day] ?? 0) + $add;
    if ($add) tg_write_json($f, [$day => $cur]);
    return $cur;
}
function tg_journal_get(?string $idem): ?array { return $idem ? tg_read_json(tg_var('ops/' . hash('sha256', $idem) . '.json')) : null; }
function tg_journal_put(?string $idem, array $res): void { if ($idem) tg_write_json(tg_var('ops/' . hash('sha256', $idem) . '.json'), $res + ['_journal_at' => time()]); }
function tg_fwd_rows(): array {
    $rows = [];
    $f = tg_var('forwarded.jsonl');
    if (is_file($f)) foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $r = json_decode($l, true); if (is_array($r)) $rows[$r['key'] . '|' . $r['src_id']] = array_merge($rows[$r['key'] . '|' . $r['src_id']] ?? [], $r); }
    return $rows;
}
function tg_fwd_append(array $row): void { file_put_contents(tg_var('forwarded.jsonl'), json_encode($row + ['at' => time()]) . "\n", FILE_APPEND | LOCK_EX); }

/** MadelineProto values → plain JSON (Bytes → base64 marker; other objects dropped). */
function tg_tl_clean($v) {
    if (is_array($v)) { $o = []; foreach ($v as $k => $x) { $o[$k] = tg_tl_clean($x); } return $o; }
    if (is_object($v)) {
        if ($v instanceof \danog\MadelineProto\TL\Types\Bytes) return ['_' => 'bytes', 'b64' => base64_encode((string) $v)];
        if ($v instanceof \JsonSerializable) return tg_tl_clean($v->jsonSerialize());
        return null;
    }
    return $v;
}
/** New message ids in send/forward updates, ascending (MadelineProto ignores caller random_id — ک7). */
function tg_update_ids(array $u): array {
    $ids = [];
    foreach ($u['updates'] ?? [] as $x) {
        if (in_array($x['_'] ?? '', ['updateNewMessage', 'updateNewChannelMessage', 'updateNewScheduledMessage'], true)) $ids[] = (int) $x['message']['id'];
    }
    if (!$ids && isset($u['id']) && ($u['_'] ?? '') === 'updateShortSentMessage') $ids[] = (int) $u['id'];
    sort($ids);
    return $ids;
}
function tg_is_channel(int $id): bool { return $id <= -1000000000000; }
function tg_get_msgs($api, int $peer, array $ids): array {
    $r = tg_is_channel($peer) ? $api->channels->getMessages(channel: $peer, id: $ids) : $api->messages->getMessages(id: $ids);
    $by = [];
    foreach ($r['messages'] ?? [] as $m) {
        if (($m['_'] ?? '') === 'messageEmpty') continue;
        if (isset($m['peer_id']) && tg_peer_id($m['peer_id']) !== $peer) continue; // private-chat ids are account-wide: Telethon filters by chat too
        $by[(int) $m['id']] = $m;
    }
    return array_map(fn($i) => isset($by[$i]) ? tg_tl_clean($by[$i]) : null, $ids);
}
function tg_rpc_name(Throwable $e): string {
    if ($e instanceof \danog\MadelineProto\RPCErrorException) return (string) $e->rpc;
    return get_class($e);
}

// ---- Drive fetch (ک9): https only, allowlisted hosts on every hop, byte cap, URL never logged ------------
function tg_fetch_drive(string $url, string $dest): int {
    for ($hop = 0; $hop < 5; $hop++) {
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        if (($p['scheme'] ?? '') !== 'https' || !($host === 'drive.google.com' || $host === 'drive.usercontent.google.com' || str_ends_with($host, '.googleusercontent.com'))) throw new RuntimeException('URL_NOT_ALLOWED');
        $fh = fopen($dest, 'wb'); $bytes = 0; $type = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'tg-host',
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$type) { if (stripos($h, 'content-type:') === 0) $type = strtolower(trim(substr($h, 13))); return strlen($h); },
            CURLOPT_WRITEFUNCTION => function ($c, $d) use ($fh, &$bytes) { $bytes += strlen($d); if ($bytes > TG_CHAIN_FILE_CAP) return 0; return fwrite($fh, $d); }]);
        curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL); curl_close($ch); fclose($fh);
        if ($code >= 300 && $code < 400 && $loc) { $url = $loc; continue; }
        if ($bytes > TG_CHAIN_FILE_CAP) { @unlink($dest); throw new RuntimeException('FILE_TOO_BIG'); }
        if ($code !== 200 || str_starts_with($type, 'text/html')) { @unlink($dest); throw new RuntimeException('DRIVE_FETCH_FAILED ' . $code); } // the virus-scan page is html
        return $bytes;
    }
    throw new RuntimeException('TOO_MANY_REDIRECTS');
}
function tg_upload_path(string $token): string {
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) throw new RuntimeException('BAD_UPLOAD');
    $p = tg_var('up/' . $token . '.bin');
    if (!is_file($p)) throw new RuntimeException('NO_UPLOAD');
    return $p;
}
/** inputMedia for one item: {upload|url, kind: photo|document, mime, name, attributes, force_document}. */
function tg_input_media($api, array $it, array &$cleanup): array {
    if (!empty($it['upload'])) $path = tg_upload_path($it['upload']);
    elseif (!empty($it['url'])) { $path = tg_var('up/' . bin2hex(random_bytes(16)) . '.dl'); tg_fetch_drive($it['url'], $path); }
    else throw new RuntimeException('MEDIA_SOURCE_MISSING');
    $cleanup[] = $path;
    $file = $api->upload($path, (string) ($it['name'] ?? basename($path)));
    if (($it['kind'] ?? 'document') === 'photo') return ['_' => 'inputMediaUploadedPhoto', 'file' => $file];
    $attrs = is_array($it['attributes'] ?? null) ? $it['attributes'] : [];
    if (!empty($it['name'])) $attrs[] = ['_' => 'documentAttributeFilename', 'file_name' => (string) $it['name']];
    return ['_' => 'inputMediaUploadedDocument', 'file' => $file, 'mime_type' => (string) ($it['mime'] ?? 'application/octet-stream'),
            'attributes' => $attrs, 'force_file' => !empty($it['force_document'])];
}
function tg_text_args(array $a): array {
    $t = (string) ($a['text'] ?? '');
    if (mb_strlen($t) > 4096) throw new RuntimeException('MESSAGE_TOO_LONG');
    return ['message' => $t, 'entities' => is_array($a['entities'] ?? null) ? $a['entities'] : [], 'no_webpage' => !empty($a['no_webpage'])];
}

// ---- the ops ---------------------------------------------------------------------------------------
function tg_chain_op($api, string $op, array $a, ?string $idem): array {
    switch ($op) {
        case 'dialogs':
            $out = [];
            foreach (tg_chain_chats()['chats'] as $id => $m) $out[] = ['id' => (int) $id] + $m;
            return ['chats' => $out];
        case 'resolve':
            $hit = tg_chain_find((string) ($a['query'] ?? ''));
            return ['chat' => $hit];
        case 'entity':
            $id = tg_chain_peer($a['peer']);
            $info = $api->getInfo($id);
            return ['entity' => tg_tl_clean($info['Chat'] ?? $info['User'] ?? null), 'type' => $info['type'] ?? null];
        case 'history':
            $peer = tg_chain_peer($a['peer']);
            $limit = min(3000, max(1, (int) ($a['limit'] ?? 100)));
            $floorId = (int) ($a['floor_id'] ?? 0); $floorDate = (int) ($a['floor_date'] ?? 0);
            $out = []; $off = (int) ($a['offset_id'] ?? 0);
            while (count($out) < $limit) {
                $n = min(100, $limit - count($out));
                $r = $api->messages->getHistory(peer: $peer, offset_id: $off, limit: $n);
                $ms = $r['messages'] ?? [];
                $stop = false;
                foreach ($ms as $m) {
                    $off = (int) $m['id'];
                    if (($m['_'] ?? '') === 'messageEmpty') continue;
                    if (($floorId && $m['id'] < $floorId) || ($floorDate && ($m['date'] ?? 0) < $floorDate)) { $stop = true; break; }
                    $out[] = tg_tl_clean($m);
                }
                if ($stop || count($ms) < $n) break;
            }
            return ['messages' => $out];
        case 'get_messages':
            $peer = tg_chain_peer($a['peer']);
            $ids = array_slice(array_map('intval', (array) ($a['ids'] ?? [])), 0, 200);
            return ['messages' => tg_get_msgs($api, $peer, $ids)];
        case 'stage':
            $peer = tg_chain_peer($a['peer'], ['source', 'archive']);
            $ids = array_slice(array_map('intval', (array) ($a['ids'] ?? [])), 0, 50);
            tg_rate('stage_hour', count($ids));
            $dir = tg_media_dir(); $files = [];
            foreach (tg_get_msgs($api, $peer, $ids) as $i => $m) {
                if ($i) usleep(random_int(2000000, 5000000)); // 2-5 s between downloads (ک19)
                if (!$m) { $files[] = ['id' => $ids[$i], 'error' => 'MESSAGE_GONE']; continue; }
                $doc = tg_m_document($m); $size = (int) ($doc['size'] ?? 0);
                if ($size > TG_CHAIN_FILE_CAP) { $files[] = ['id' => $ids[$i], 'error' => 'FILE_TOO_BIG', 'size' => $size]; continue; }
                if (tg_night_bytes() + $size > TG_CHAIN_NIGHT_CAP) { $files[] = ['id' => $ids[$i], 'error' => 'NIGHT_CAP']; continue; }
                if (tg_dir_bytes(TG_BASE . '/public_html/m') + $size > TG_CHAIN_STAGE_CAP) { $files[] = ['id' => $ids[$i], 'error' => 'STAGE_CAP']; continue; }
                $f = tg_download($api, $m, $dir);
                tg_night_bytes((int) $f['bytes']);
                $files[] = ['id' => $ids[$i]] + $f;
            }
            return ['files' => $files];
        case 'forward':
            $from = tg_chain_peer($a['from'], ['source']); $to = tg_chain_peer($a['to'], ['archive']);
            $key = (string) ($a['key'] ?? ''); if (!preg_match('/^[A-Za-z0-9_-]{2,40}$/', $key)) throw new RuntimeException('BAD_KEY');
            $ids = array_map('intval', (array) ($a['ids'] ?? []));
            if (!$ids || count($ids) > 100) throw new RuntimeException('forward: 1..100 ids');
            $log = tg_fwd_rows();
            $prev = array_values(array_filter($ids, fn($i) => !empty($log["$key|$i"]['dst_id'])));
            if ($prev) { // a copy deleted from the archive since (nightly reset) is forwarded again, not reported as done
                $got = tg_get_msgs($api, $to, array_map(fn($i) => (int) $log["$key|$i"]['dst_id'], $prev));
                foreach ($prev as $k => $i) { if (!$got[$k]) unset($log["$key|$i"]['dst_id']); }
            }
            $todo = array_values(array_filter($ids, fn($i) => empty($log["$key|$i"]['dst_id'])));
            if ($todo) {
                tg_rate('forward_day', count($todo));
                $u = $api->messages->forwardMessages(from_peer: $from, to_peer: $to, id: $todo, drop_author: true, drop_media_captions: !empty($a['drop_media_captions']));
                $new = tg_update_ids($u);
                foreach ($todo as $k => $src) { tg_fwd_append(['key' => $key, 'src' => $from, 'src_id' => $src, 'dst' => $to, 'dst_id' => $new[$k] ?? null, 'verified' => false]); }
                $fresh = tg_fwd_rows();
                foreach ($todo as $i) $log["$key|$i"] = $fresh["$key|$i"] ?? [];
            }
            return ['ids' => array_map(fn($i) => $log["$key|$i"]['dst_id'] ?? null, $ids)];
        case 'verify':
            $key = (string) ($a['key'] ?? '');
            $rows = array_filter(tg_fwd_rows(), fn($r) => $r['key'] === $key && !empty($r['dst_id']));
            if (!$rows) return ['verified' => 0, 'missing' => 0];
            $byDst = [];
            foreach ($rows as $r) $byDst[(int) $r['dst']][] = $r;
            $ok = 0; $miss = [];
            foreach ($byDst as $dst => $rs) {
                $got = tg_get_msgs($api, $dst, array_map(fn($r) => (int) $r['dst_id'], $rs));
                foreach ($rs as $i => $r) {
                    if ($got[$i] && tg_m_media($got[$i])) { $ok++; if (empty($r['verified'])) tg_fwd_append(['key' => $key, 'src_id' => $r['src_id'], 'verified' => true, 'verified_at' => time()]); }
                    else $miss[] = (int) $r['src_id'];
                }
            }
            return ['verified' => $ok, 'missing' => count($miss), 'missing_src_ids' => $miss];
        case 'send_text':
            $peer = tg_chain_peer($a['peer'], TG_CHAIN_KINDS_WRITE);
            tg_rate('send_hour');
            $t = tg_text_args($a);
            $u = $api->messages->sendMessage(peer: $peer, message: $t['message'], entities: $t['entities'], no_webpage: $t['no_webpage'],
                reply_to: !empty($a['reply_to']) ? ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => (int) $a['reply_to']] : null);
            $ids = tg_update_ids($u);
            return ['ids' => $ids, 'messages' => $ids ? tg_get_msgs($api, $peer, $ids) : []];
        case 'edit_text':
            $peer = tg_chain_peer($a['peer'], TG_CHAIN_KINDS_WRITE);
            tg_rate('edit_hour');
            $t = tg_text_args($a);
            $api->messages->editMessage(peer: $peer, id: (int) $a['id'], message: $t['message'], entities: $t['entities'], no_webpage: $t['no_webpage']);
            return ['messages' => tg_get_msgs($api, $peer, [(int) $a['id']])];
        case 'pin':
            $peer = tg_chain_peer($a['peer'], TG_CHAIN_KINDS_WRITE);
            tg_rate('edit_hour');
            $api->messages->updatePinnedMessage(peer: $peer, id: (int) $a['id'], silent: !empty($a['silent']), unpin: !empty($a['unpin']));
            return ['ok' => true];
        case 'send_media':
            $peer = tg_chain_peer($a['peer'], TG_CHAIN_KINDS_WRITE);
            $items = array_slice((array) ($a['items'] ?? []), 0, 10);
            if (!$items) throw new RuntimeException('send_media: no items');
            tg_rate('send_hour');
            $cleanup = [];
            try {
                $t = tg_text_args(['text' => $a['caption'] ?? '', 'entities' => $a['entities'] ?? []]);
                $reply = !empty($a['reply_to']) ? ['_' => 'inputReplyToMessage', 'reply_to_msg_id' => (int) $a['reply_to']] : null;
                if (count($items) === 1) {
                    $u = $api->messages->sendMedia(peer: $peer, media: tg_input_media($api, $items[0], $cleanup), message: $t['message'], entities: $t['entities'], reply_to: $reply);
                } else {
                    $multi = [];
                    foreach ($items as $k => $it) {
                        $uploaded = $api->messages->uploadMedia(peer: $peer, media: tg_input_media($api, $it, $cleanup));
                        $multi[] = ['_' => 'inputSingleMedia', 'media' => $uploaded, 'message' => $k === 0 ? $t['message'] : '', 'entities' => $k === 0 ? $t['entities'] : []];
                    }
                    $u = $api->messages->sendMultiMedia(peer: $peer, multi_media: $multi, reply_to: $reply);
                }
            } finally { foreach ($cleanup as $p) @unlink($p); }
            $ids = tg_update_ids($u);
            return ['ids' => $ids, 'messages' => $ids ? tg_get_msgs($api, $peer, $ids) : []];
        case 'purge_own':
            $peer = tg_chain_peer($a['peer'], TG_CHAIN_KINDS_WRITE);
            $ids = array_slice(array_map('intval', (array) ($a['ids'] ?? [])), 0, 100);
            tg_rate('edit_hour');
            $own = [];
            foreach (tg_get_msgs($api, $peer, $ids) as $i => $m) { if ($m && !empty($m['out'])) $own[] = $ids[$i]; }
            if ($own) { tg_is_channel($peer) ? $api->channels->deleteMessages(channel: $peer, id: $own) : $api->messages->deleteMessages(revoke: true, id: $own); }
            return ['deleted' => $own, 'refused' => array_values(array_diff($ids, $own))];
        case 'delete_source':
            // ک5 fence, checked at deletion time: each id is a media message this host forwarded in the last 7 days whose copy
            // is still in the archive with its media, or a media-less companion (the bot's link message) within 20 ids of one.
            $peer = tg_chain_peer($a['peer'], ['source']);
            $key = (string) ($a['key'] ?? '');
            $ids = array_values(array_unique(array_map('intval', (array) ($a['ids'] ?? []))));
            if (!$ids || count($ids) > 60) throw new RuntimeException('delete_source: 1..60 ids');
            sort($ids);
            $rows = [];
            foreach (tg_fwd_rows() as $r) {
                if ((int) ($r['src'] ?? 0) === $peer && !empty($r['dst_id']) && ($r['at'] ?? 0) >= time() - TG_CHAIN_FWD_VERIFY_S && ($key === '' || $r['key'] === $key) && in_array((int) $r['src_id'], $ids, true)) $rows[(int) $r['src_id']] = $r;
            }
            $ok = [];
            $byDst = [];
            foreach ($rows as $src => $r) $byDst[(int) $r['dst']][$src] = (int) $r['dst_id'];
            foreach ($byDst as $dst => $pairs) {
                $got = tg_get_msgs($api, $dst, array_values($pairs));
                $k = 0; foreach ($pairs as $src => $_) { if ($got[$k] && (tg_m_document($got[$k]) || tg_m_photo($got[$k]))) $ok[] = $src; $k++; }
            }
            if (!$ok) throw new RuntimeException('DELETE_FENCE: no id has a forwarded copy still in the archive');
            $cur = tg_get_msgs($api, $peer, $ids); $todo = []; $bad = [];
            foreach ($ids as $n => $i) {
                if (!$cur[$n]) continue; // already gone
                if (in_array($i, $ok, true)) { $todo[] = $i; continue; }
                $near = array_filter($ok, fn($v) => abs($v - $i) <= 20);
                if ($near && !tg_m_document($cur[$n]) && !tg_m_photo($cur[$n])) { $todo[] = $i; continue; }
                $bad[] = $i;
            }
            if ($bad) throw new RuntimeException('DELETE_FENCE: ' . count($bad) . ' id(s) without an archived copy: ' . implode(',', array_slice($bad, 0, 10)));
            $digest = hash('sha256', $key . '|' . implode(',', $ids));
            $cdir = tg_dir(tg_var('confirm'));
            if (empty($a['confirm'])) {
                $token = bin2hex(random_bytes(16));
                tg_write_json($cdir . '/' . $token . '.json', ['digest' => $digest, 'expires' => time() + 900]);
                return ['preview' => true, 'ids' => $ids, 'to_delete' => $todo, 'confirm_token' => $token];
            }
            $tf = $cdir . '/' . preg_replace('/[^0-9a-f]/', '', (string) $a['confirm']) . '.json';
            $t = tg_read_json($tf); if (is_file($tf)) unlink($tf);
            if (!$t || $t['digest'] !== $digest || $t['expires'] < time()) throw new RuntimeException('DELETE_FENCE: confirm token invalid');
            if ($todo) { tg_rate('delete_night', count($todo)); $api->messages->deleteMessages(revoke: true, id: $todo); }
            $left = array_values(array_filter(array_map(fn($m, $i) => $m ? $i : null, tg_get_msgs($api, $peer, $ids), $ids)));
            tg_log('chain', 'delete_source requested=' . count($ids) . ' deleted=' . count($todo) . ' still=' . count($left));
            return ['deleted' => true, 'pts_count' => count($todo), 'still_present' => $left];
        case 'create_channel':
            $c = tg_chain_chats();
            if (count($c['chats']) >= TG_CHAIN_MAX_CHATS) throw new RuntimeException('CHAIN_CHAT_CAP');
            tg_rate('create_day'); tg_rate('create_week');
            $title = mb_substr((string) ($a['title'] ?? ''), 0, 128); if ($title === '') throw new RuntimeException('create_channel: title');
            $u = $api->channels->createChannel(broadcast: true, title: $title, about: mb_substr((string) ($a['about'] ?? ''), 0, 255));
            $ch = $u['chats'][0] ?? null; if (!$ch) throw new RuntimeException('create_channel: no chat in updates');
            $id = -1000000000000 - (int) $ch['id'];
            $c = tg_chain_chats(); $c['chats'][(string) $id] = ['kind' => 'created', 'title' => $title, 'label' => $title, 'added' => time()]; tg_chain_save($c);
            return ['id' => $id, 'chats' => [tg_tl_clean($ch)]];
        case 'edit_title':
            $peer = tg_chain_peer($a['peer'], TG_CHAIN_KINDS_WRITE);
            tg_rate('edit_hour');
            $title = mb_substr((string) ($a['title'] ?? ''), 0, 128);
            try { $api->channels->editTitle(channel: $peer, title: $title); }
            catch (\danog\MadelineProto\RPCErrorException $e) { if ($e->rpc !== 'CHAT_NOT_MODIFIED') throw $e; }
            $c = tg_chain_chats(); $c['chats'][(string) $peer]['title'] = $title; tg_chain_save($c);
            return ['ok' => true, 'title' => $title];
        case 'edit_photo':
            $peer = tg_chain_peer($a['peer'], TG_CHAIN_KINDS_WRITE);
            tg_rate('edit_hour');
            $path = tg_upload_path((string) ($a['upload'] ?? ''));
            try { $api->channels->editPhoto(channel: $peer, photo: ['_' => 'inputChatUploadedPhoto', 'file' => $api->upload($path, 'photo.jpg')]); }
            finally { @unlink($path); }
            return ['ok' => true];
    }
    throw new RuntimeException('UNKNOWN_OP: ' . $op);
}

/** The chain job: a batch of named ops in one MadelineProto session; `$N.path` in a string arg refers to op N's result. */
function job_chain_ops(array $a, callable $hb, string $role = 'read'): array {
    tg_chain_lease_check((string) $a['run'], (int) $a['lease']);
    $ops = json_decode($a['ops'], true);
    if (!is_array($ops) || !$ops || count($ops) > 60) throw new RuntimeException('chain_ops: 1..60 ops');
    return tg_mp_with(tg_session_dir(), function ($api) use ($ops, $hb) {
        $results = [];
        foreach ($ops as $n => $o) {
            $op = (string) ($o['op'] ?? ''); $args = is_array($o['args'] ?? null) ? $o['args'] : []; $idem = isset($o['idem']) ? (string) $o['idem'] : null;
            array_walk_recursive($args, function (&$v) use ($results) {
                if (is_string($v) && preg_match('/^\$(\d+)\.([A-Za-z0-9_.]+)$/', $v, $m)) { $x = $results[(int) $m[1]] ?? null; foreach (explode('.', $m[2]) as $k) { $x = is_array($x) ? ($x[$k] ?? null) : null; } $v = $x; }
            });
            $hb(['phase' => 'op', 'n' => $n, 'op' => $op]);
            if ($prev = tg_journal_get($idem)) { $results[] = $prev + ['journalled' => true]; continue; }
            try {
                $res = tg_chain_op($api, $op, $args, $idem);
            } catch (Throwable $e) {
                $name = tg_rpc_name($e);
                $results[] = ['error' => $name, 'message' => mb_substr(preg_replace('/[A-Za-z0-9_\-]{25,}/', '…', $e->getMessage()), 0, 200)];
                if (str_contains($name, 'FLOOD') || str_contains($e->getMessage(), 'FLOOD')) { tg_log('chain', 'flood stop at op ' . $n); break; } // ک6: stop the batch, report
                if (!empty($o['stop_on_error'])) break;
                continue;
            }
            tg_journal_put($idem, $res);
            $results[] = $res;
        }
        return ['results' => $results];
    }, TG_CHAIN_OPS_BUDGET_S);
}

/** Admin-only ops: seed/remove the allowlist (no person chats), bot admin rights, delete a host-created channel, arm the watchdog. */
function job_chain_admin(array $a, callable $hb, string $role = 'read'): array {
    $ops = json_decode($a['ops'], true);
    if (!is_array($ops)) throw new RuntimeException('chain_admin: ops');
    return tg_mp_with(tg_session_dir(), function ($api) use ($ops) {
        $out = [];
        foreach ($ops as $o) {
            $args = $o['args'] ?? [];
            try {
                switch ($o['op'] ?? '') {
                    case 'list_dialogs':
                        $rows = [];
                        foreach ($api->getDialogIds() as $id) { $i = $api->getInfo($id); $u = $i['User'] ?? null; $ch = $i['Chat'] ?? null;
                            $rows[] = ['id' => $id, 'type' => $i['type'] ?? null, 'title' => $ch['title'] ?? trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')), 'bot' => (bool) ($u['bot'] ?? false)]; }
                        $out[] = ['dialogs' => $rows]; break;
                    case 'allow':
                        $id = (int) $args['peer']; $kind = (string) $args['kind'];
                        if (!in_array($kind, ['source', 'archive', 'control', 'panel', 'created'], true)) throw new RuntimeException('bad kind');
                        $i = $api->getInfo($id);
                        if (($i['type'] ?? '') === 'user' && empty($i['User']['bot'])) throw new RuntimeException('PERSON_REFUSED: only channels, groups and bots');
                        $c = tg_chain_chats();
                        if (!isset($c['chats'][(string) $id]) && count($c['chats']) >= TG_CHAIN_MAX_CHATS) throw new RuntimeException('CHAIN_CHAT_CAP');
                        $title = $i['Chat']['title'] ?? trim(($i['User']['first_name'] ?? '') . ' ' . ($i['User']['last_name'] ?? ''));
                        $c['chats'][(string) $id] = ['kind' => $kind, 'title' => $title, 'label' => (string) ($args['label'] ?? $title), 'added' => time()];
                        tg_chain_save($c); $out[] = ['allowed' => $id, 'title' => $title, 'kind' => $kind]; break;
                    case 'unallow':
                        $c = tg_chain_chats(); unset($c['chats'][(string) (int) $args['peer']]); tg_chain_save($c); $out[] = ['removed' => (int) $args['peer']]; break;
                    case 'edit_admin':
                        $peer = tg_chain_peer($args['peer'], TG_CHAIN_KINDS_WRITE);
                        $bot = (string) $args['bot']; if (!preg_match('/^@?[A-Za-z0-9_]{5,32}bot$/i', $bot)) throw new RuntimeException('not a bot username');
                        $api->channels->editAdmin(channel: $peer, user_id: $bot, rank: 'menu', admin_rights: ['_' => 'chatAdminRights', 'post_messages' => true, 'edit_messages' => true, 'delete_messages' => true, 'pin_messages' => true]);
                        $out[] = ['ok' => true]; break;
                    case 'delete_channel':
                        $peer = tg_chain_peer($args['peer'], ['created']);
                        $api->channels->deleteChannel(channel: $peer);
                        $c = tg_chain_chats(); unset($c['chats'][(string) $peer]); tg_chain_save($c); $out[] = ['deleted' => $peer]; break;
                    case 'intake_token':
                        $out[] = tg_chain_intake_token(); break;
                    case 'arm':
                        if (!empty($args['on'])) file_put_contents(tg_var('chain-armed'), (string) time()); else @unlink(tg_var('chain-armed'));
                        $out[] = ['armed' => !empty($args['on'])]; break;
                    default: throw new RuntimeException('unknown admin op');
                }
            } catch (Throwable $e) { $out[] = ['error' => tg_rpc_name($e), 'message' => mb_substr($e->getMessage(), 0, 200)]; }
        }
        return ['results' => $out];
    });
}

// ---- lease + heartbeat (driven from run.php, no MadelineProto) ---------------------------------------
function tg_chain_lease_check(string $run, int $token): void {
    $l = tg_read_json(tg_var('chain-lease.json'));
    if (!$l || $l['run'] !== $run || (int) $l['token'] !== $token || $l['exp'] < time()) throw new RuntimeException('LEASE: not the current chain lease holder');
}
/** acquire|renew|release|status under one flock; returns [http code, body]. */
function tg_chain_lease(array $in): array {
    $fh = fopen(tg_var('chain-lease.lock'), 'c'); flock($fh, LOCK_EX);
    try {
        $f = tg_var('chain-lease.json'); $l = tg_read_json($f); $now = time();
        $op = (string) ($in['op'] ?? 'status'); $run = (string) ($in['run'] ?? ''); $ttl = max(60, min(7200, (int) ($in['ttl'] ?? 900)));
        if (!preg_match('/^[A-Za-z0-9_.:-]{6,80}$/', $run) && $op !== 'status') return [400, ['error' => 'bad run id']];
        $live = $l && $l['exp'] >= $now;
        if ($op === 'status') { $k = include __DIR__ . '/../keys.php'; return [200, ['ok' => true, 'held' => $live, 'run' => $live ? $l['run'] : null, 'exp' => $live ? $l['exp'] : null, 'read_key_holds_chain' => ($k['read'] ?? '') !== '' && ($k['read'] ?? '') === ($k['chain'] ?? '')]]; }
        if ($op === 'acquire') {
            if ($live && $l['run'] !== $run) return [409, ['error' => 'held', 'run' => $l['run'], 'exp' => $l['exp']]];
            $l = ['run' => $run, 'token' => (int) (($l['token'] ?? 0) + 1), 'exp' => $now + $ttl, 'since' => $now];
            tg_write_json($f, $l); return [200, ['ok' => true, 'token' => $l['token'], 'exp' => $l['exp']]];
        }
        if (!$live || $l['run'] !== $run || (int) ($in['token'] ?? -1) !== (int) $l['token']) return [409, ['error' => 'not holder']];
        if ($op === 'renew') { $l['exp'] = $now + $ttl; tg_write_json($f, $l); return [200, ['ok' => true, 'token' => $l['token'], 'exp' => $l['exp']]]; }
        if ($op === 'release') { $l['exp'] = 0; tg_write_json($f, $l); return [200, ['ok' => true]]; }
        return [400, ['error' => 'bad op']];
    } finally { flock($fh, LOCK_UN); fclose($fh); }
}
/** Session heartbeat: start | bundle | delivered | end, for a night (Tehran date of the 03:00 run). */
function tg_chain_beat(array $in): array {
    $ev = (string) ($in['event'] ?? ''); $night = (string) ($in['night'] ?? '');
    if (!in_array($ev, ['start', 'bundle', 'delivered', 'end', 'off'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $night)) return [400, ['error' => 'bad beat']];
    $f = tg_var('chain-night.json'); $r = tg_read_json($f) ?? [];
    $r[$night][$ev] = time(); $r[$night]['last'] = time();
    if (isset($in['note'])) $r[$night]['note'] = mb_substr((string) $in['note'], 0, 200);
    $r = array_slice($r, -14, null, true);
    tg_write_json($f, $r);
    return [200, ['ok' => true]];
}
/** Dead-man switch (ک16), from tick.php before any library: no "delivered" by 05:00 / 16:00 Tehran ⇒ Bot API alert. */
function tg_chain_watchdog(): void {
    if (!is_file(tg_var('chain-armed'))) return;
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
    $hm = (int) $now->format('H') * 60 + (int) $now->format('i');
    $slot = ($hm >= 300 && $hm < 330) ? 'morning' : (($hm >= 960 && $hm < 990) ? 'afternoon' : null);
    if (!$slot) return;
    $night = $now->format('Y-m-d');
    $r = tg_read_json(tg_var('chain-night.json')) ?? [];
    if (!empty($r[$night]['delivered']) || !empty($r[$night]['off']) || !empty($r[$night]['alert_' . $slot])) return;
    $s = tg_chain_secrets() + tg_secrets(); $tok = (string) ($s['TELEGRAM_BOT_TOKEN'] ?? ''); $chat = (string) ($s['TELEGRAM_POOL_CHAT_ID'] ?? '');
    $state = empty($r[$night]['start']) ? 'هیچ اجرایی شروع نشد' : 'شروع شد ولی به «تحویل» نرسید';
    $text = "⚠️ زنجیرهٔ چهارگام — شبِ $night : $state (نگهبانِ هاست، " . ($slot === 'morning' ? '۰۵:۰۰' : '۱۶:۰۰') . ' تهران).';
    $sent = false;
    if ($tok === '' || $chat === '') {
        // no bot secrets on the host: queue an internal job that posts from the host's own session to the first
        // allowlisted control/archive channel (no door role can submit chain_alert)
        $id = tg_id();
        tg_status($id, ['id' => $id, 'job' => 'chain_alert', 'state' => 'queued', 'created_at' => time(), 'role' => 'internal']);
        tg_write_json(tg_dir(tg_var('queue')) . '/' . time() . '-chain_alert-' . $id . '.json', ['id' => $id, 'job' => 'chain_alert', 'args' => ['text' => $text], 'role' => 'internal', 'attempt' => 0, 'not_before' => 0]);
        $sent = 'queued';
    } else {
        $ch = curl_init('https://api.telegram.org/bot' . $tok . '/sendMessage');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'text' => $text], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $sent = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200; curl_close($ch);
    }
    $r[$night]['alert_' . $slot] = time(); $r[$night]['alert_sent_' . $slot] = $sent;
    tg_write_json(tg_var('chain-night.json'), $r);
    tg_log('chain', "watchdog $slot night=$night sent=" . ($sent ? 1 : 0));
}

/** Internal (no door role): post a watchdog alert from the host session to the first allowlisted control/archive channel. */
function job_chain_alert(array $a, callable $hb, string $role = 'read'): array {
    if ($role !== 'internal') throw new RuntimeException('internal job');
    $target = null;
    foreach (['control', 'archive'] as $k) { foreach (tg_chain_chats()['chats'] as $id => $m) { if ($m['kind'] === $k) { $target = (int) $id; break 2; } } }
    if (!$target) throw new RuntimeException('no control/archive chat to alert');
    return tg_mp_with(tg_session_dir(), function ($api) use ($a, $target) {
        $api->messages->sendMessage(peer: $target, message: mb_substr((string) $a['text'], 0, 1000));
        return ['sent_to' => $target];
    });
}

// ---- secrets held by the host for the chain (ک25): one-time intake, Drive token vending, Bot API relay ---------
const TG_CHAIN_SECRET_KEYS = ['DRIVE_OAUTH', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_POOL_CHAT_ID', 'TG_ARCHIVE_CHAT_ID'];
function tg_chain_secrets(): array { return tg_read_json(tg_var('chain-secrets.json')) ?? []; }
/** Admin op: a single-use intake token, valid 10 minutes; only its sha256 is kept. */
function tg_chain_intake_token(): array {
    $t = bin2hex(random_bytes(24));
    tg_write_json(tg_var('chain-intake.json'), ['sha' => hash('sha256', $t), 'exp' => time() + 600]);
    return ['intake_token' => $t, 'expires_in_s' => 600];
}
/** Door action `intake` (no key; the single-use token is the credential). Values are never echoed. */
function tg_chain_intake(string $token, array $in): array {
    $f = tg_var('chain-intake.json'); $t = tg_read_json($f);
    if (is_file($f)) unlink($f); // single use, whatever happens next
    if (!$t || $t['exp'] < time() || !hash_equals($t['sha'], hash('sha256', $token))) return [403, ['error' => 'intake token invalid or used']];
    $cur = tg_chain_secrets(); $got = [];
    foreach (TG_CHAIN_SECRET_KEYS as $k) { if (isset($in['secrets'][$k]) && is_string($in['secrets'][$k]) && $in['secrets'][$k] !== '') { $cur[$k] = $in['secrets'][$k]; $got[] = $k; } }
    $p = tg_var('chain-secrets.json'); tg_write_json($p, $cur); @chmod($p, 0600);
    tg_log('chain', 'intake keys=' . implode(',', $got));
    return [200, ['ok' => true, 'stored' => $got]];
}
function tg_tehran_minutes(): int { $n = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')); return (int) $n->format('H') * 60 + (int) $n->format('i'); }
/** Bot API call with the host-held token; chat ids must be the pool/archive ids or a chain chat (ک25-c). */
function tg_bot_call(string $method, array $params, array $files = []): array {
    $s = tg_chain_secrets(); $tok = (string) ($s['TELEGRAM_BOT_TOKEN'] ?? '');
    if ($tok === '') return ['ok' => false, 'description' => 'no bot token on host'];
    $ch = curl_init('https://api.telegram.org/bot' . $tok . '/' . $method);
    $post = $params;
    foreach ($files as $field => $path) $post[$field] = new CURLFile($path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    $body = curl_exec($ch); curl_close($ch);
    $r = is_string($body) ? json_decode($body, true) : null;
    return is_array($r) ? $r : ['ok' => false, 'description' => 'bot api unreachable'];
}
function tg_bot_chat_allowed($chat): bool {
    $s = tg_chain_secrets(); $c = (string) $chat;
    if ($c !== '' && ($c === (string) ($s['TELEGRAM_POOL_CHAT_ID'] ?? '') || $c === (string) ($s['TG_ARCHIVE_CHAT_ID'] ?? ''))) return true;
    return isset(tg_chain_chats()['chats'][$c]);
}
/** Door action `bot` (chain/admin): allowlisted methods and chats, rate-capped; files arrive as upload tokens. */
function tg_chain_bot(array $in): array {
    $method = (string) ($in['method'] ?? '');
    if (!in_array($method, ['sendMessage', 'sendPhoto', 'sendDocument', 'editMessageText', 'deleteMessage', 'pinChatMessage', 'unpinChatMessage', 'getMe'], true)) return [403, ['error' => 'method not allowed']];
    $params = is_array($in['params'] ?? null) ? $in['params'] : [];
    if ($method !== 'getMe' && !tg_bot_chat_allowed($params['chat_id'] ?? '')) return [403, ['error' => 'chat not allowed']];
    foreach ($params as $k => $v) { if (is_array($v)) $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE); } // reply_markup etc.
    try { tg_rate('bot_hour'); } catch (Throwable $e) { return [429, ['error' => $e->getMessage()]]; }
    $files = [];
    foreach ((array) ($in['files'] ?? []) as $field => $tok) {
        if (!in_array($field, ['photo', 'document'], true)) return [400, ['error' => 'bad file field']];
        try { $files[$field] = tg_upload_path((string) $tok); } catch (Throwable $e) { return [400, ['error' => $e->getMessage()]]; }
    }
    $r = tg_bot_call($method, $params, $files);
    foreach ($files as $p) @unlink($p);
    return [200, $r];
}
/** Door action `drive_token` (ک25-b): the current chain-lease holder, inside routine windows (admin anytime), ≤12 a day; every mint is announced. */
function tg_chain_drive_token(array $in, array $roles): array {
    $admin = in_array('admin', $roles, true);
    if (!$admin) {
        try { tg_chain_lease_check((string) ($in['run'] ?? ''), (int) ($in['lease'] ?? 0)); } catch (Throwable $e) { return [409, ['error' => 'lease required']]; }
        $m = tg_tehran_minutes();
        $win = ($m >= 165 && $m < 390) || ($m >= 465 && $m < 600) || ($m >= 765 && $m < 900); // 02:45-06:30, 07:45-10:00, 12:45-15:00
        if (!$win) return [403, ['error' => 'outside chain windows']];
    }
    try { tg_rate('drive_mint_day'); } catch (Throwable $e) { return [429, ['error' => 'daily drive token cap reached — minting locked until tomorrow']]; }
    $raw = (string) (tg_chain_secrets()['DRIVE_OAUTH'] ?? '');
    $kv = [];
    foreach (preg_split('/\R/', $raw) as $l) { if (str_contains($l, '=')) { [$k, $v] = explode('=', $l, 2); $kv[trim($k)] = trim(trim($v), "\"'"); } }
    if (empty($kv['CLIENT_ID']) || empty($kv['CLIENT_SECRET']) || empty($kv['REFRESH_TOKEN'])) return [503, ['error' => 'no drive credentials on host']];
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $kv['CLIENT_ID'], 'client_secret' => $kv['CLIENT_SECRET'], 'refresh_token' => $kv['REFRESH_TOKEN'], 'grant_type' => 'refresh_token'])]);
    $r = json_decode((string) curl_exec($ch), true); curl_close($ch);
    if (empty($r['access_token'])) return [502, ['error' => 'google refused the refresh']];
    $n = count((tg_read_json(tg_var('chain-rate.json')) ?? [])['drive_mint_day'] ?? []);
    tg_log('chain', 'drive token minted #' . $n . ($admin ? ' (admin)' : ''));
    $pool = (string) (tg_chain_secrets()['TELEGRAM_POOL_CHAT_ID'] ?? '');
    if ($pool !== '') tg_bot_call('sendMessage', ['chat_id' => $pool, 'text' => "🔑 توکنِ یک‌ساعتهٔ درایو برای زنجیره صادر شد (شمارهٔ $n امروز از ۱۲)" . ($admin ? ' — با کلیدِ مدیر' : '') . '.']);
    return [200, ['ok' => true, 'access_token' => $r['access_token'], 'expires_in' => (int) ($r['expires_in'] ?? 3600), 'mint' => $n]];
}
