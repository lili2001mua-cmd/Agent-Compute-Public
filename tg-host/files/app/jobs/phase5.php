<?php
// Phase-5 job: delete messages — the fenced port of Agent-Project-Backup/Sys.scripts/telegram_delete.py.
// Fences (manifest, row "delete"): only the allowlisted chat (Instant Saver), whatever the key; only ids this host
// itself staged through job_batches in the last 72 h (var/produced/); at most TG_DELETE_MAX ids; preview by default;
// a real delete needs the single-use token the preview returned (bound to the exact id set, 15 min);
// the right method by chat type (channels.deleteMessages vs messages.deleteMessages); counts-only logging; never logout().

const TG_DELETE_MAX = 60;
const TG_PRODUCED_TTL_S = 72 * 3600;
const TG_CONFIRM_TTL_S = 15 * 60;

function tg_produced_ids(int $chatId): array {
    $ok = [];
    foreach (glob(tg_var('produced') . '/*.json') ?: [] as $f) {
        $r = tg_read_json($f);
        if (!$r || ($r['at'] ?? 0) < time() - TG_PRODUCED_TTL_S) { @unlink($f); continue; }
        if ((int) ($r['chat_id'] ?? 0) === $chatId) foreach ($r['ids'] ?? [] as $i) $ok[(int) $i] = true;
    }
    return $ok;
}

function job_delete(array $a, callable $hb, string $role = 'read'): array {
    if (!in_array(mb_strtolower(trim($a['query'])), TG_READ_CHATS, true)) throw new RuntimeException('delete: chat not allowlisted');
    $ids = array_values(array_unique(array_map('intval', array_filter(explode(',', $a['ids']), 'strlen'))));
    sort($ids);
    if (!$ids || count($ids) > TG_DELETE_MAX) throw new RuntimeException('delete: 1..' . TG_DELETE_MAX . ' ids');
    $digest = hash('sha256', implode(',', $ids));
    return tg_mp_with(tg_session_dir(), function ($api) use ($a, $ids, $digest) {
        $d = tg_find_dialog($api, $a['query']);
        if (!$d) return ['error' => 'NO_CHAT'];
        $produced = tg_produced_ids($d['id']);
        $foreign = array_values(array_filter($ids, fn($i) => !isset($produced[$i])));
        if ($foreign) throw new RuntimeException('delete: ' . count($foreign) . ' id(s) were not staged by this host in the last 72 h');
        $cdir = tg_dir(tg_var('confirm'));
        if ($a['confirm'] === '') {
            $token = bin2hex(random_bytes(16));
            tg_write_json($cdir . '/' . $token . '.json', ['digest' => $digest, 'chat_id' => $d['id'], 'expires' => time() + TG_CONFIRM_TTL_S]);
            return ['preview' => true, 'chat' => $d['name'], 'chat_id' => $d['id'], 'ids' => $ids, 'confirm_token' => $token, 'expires_in_s' => TG_CONFIRM_TTL_S];
        }
        $tf = $cdir . '/' . $a['confirm'] . '.json';
        $t = tg_read_json($tf);
        @unlink($tf); // single use, whatever happens next
        if (!$t || $t['digest'] !== $digest || (int) $t['chat_id'] !== $d['id'] || $t['expires'] < time()) throw new RuntimeException('delete: confirm token invalid, used, expired or for other ids');
        $isChannel = $d['id'] <= -1000000000000;
        $r = $isChannel ? $api->channels->deleteMessages(channel: $d['id'], id: $ids) : $api->messages->deleteMessages(revoke: true, id: $ids);
        $affected = (int) ($r['pts_count'] ?? 0);
        $left = [];
        $got = $isChannel ? $api->channels->getMessages(channel: $d['id'], id: $ids) : $api->messages->getMessages(id: $ids);
        foreach ($got['messages'] ?? [] as $m) { if (($m['_'] ?? '') !== 'messageEmpty') $left[] = (int) $m['id']; }
        tg_log('delete', 'requested=' . count($ids) . ' affected=' . $affected . ' still=' . count($left));
        return ['deleted' => true, 'chat' => $d['name'], 'requested' => count($ids), 'affected' => $affected, 'still_present' => $left];
    }, 300);
}
